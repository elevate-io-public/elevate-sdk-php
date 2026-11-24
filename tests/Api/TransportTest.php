<?php

declare(strict_types=1);

namespace Elevate\Tests\Api;

use Elevate\Api\Transport;
use Elevate\Auth\Tokens;
use Elevate\Config;
use Elevate\Exception\ApiException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;
use Elevate\Tests\FixedClock;
use Elevate\Tests\Generator\Expected\Model\Author;
use Elevate\Tests\Generator\Expected\Model\BookList;
use Elevate\Tests\Generator\Expected\Model\Condition;
use Elevate\Tests\Generator\Expected\Model\Genre;
use Elevate\Tests\Generator\Expected\Model\Hardcover;
use Elevate\Tests\Generator\Expected\Model\NewBook;
use Elevate\Tests\Generator\FixtureClient;
use Elevate\Tests\Keys;
use Elevate\Tests\RecordingHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the request and response shapes that the generated operations use, through the generator's fixture
 * operations. ApiClientTest covers the refresh and the errors.
 */
final class TransportTest extends TestCase
{
    private const int NOW = 1_800_000_000;
    private const string SHELF_ID = '851c9c60-ac5e-440e-8e77-bdb75071f6b4';
    private const array BOOK = [
        'id' => 'aee01c1c-2967-45e3-91a4-6e5f23d5563c',
        'pages' => 320,
        'signed' => false,
        'author' => ['name' => 'Ada'],
        'genre' => 'NON_FICTION',
        'tags' => ['history', 'science'],
    ];

    private RecordingHttpClient $httpClient;
    private FixtureClient $client;

    protected function setUp(): void
    {
        $this->httpClient = new RecordingHttpClient();
        $clock = FixedClock::at(self::NOW);
        $this->client = new FixtureClient(new Transport(
            new Config('client-id', Keys::ecPrivateKey(), 'key-id', 'https://auth.example.test', 'https://api.example.test'),
            new Tokens('the-access-token', 'the-refresh-token', $clock->now()->modify('+1 hour'), ['books:read']),
            static function (Tokens $tokens): void {},
            $this->httpClient,
            $clock,
        ));
    }

    public function testSendsTheQueryParametersThatHaveAValue(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => []]);

        $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction, signed: false, after: 'a b&c');

        $request = $this->httpClient->requests[0];
        self::assertSame('GET', $request->method);
        self::assertSame('https://api.example.test/v1/shelves/'.self::SHELF_ID.'/books?genre=NON_FICTION&signed=false&after=a%20b%26c', $request->url);
        self::assertSame('', $request->body);
        self::assertArrayNotHasKey('Content-Type', $request->headers);
    }

    public function testSendsEveryQueryParameter(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => []]);

        $this->client->books->listBooks(self::SHELF_ID, Genre::Fiction, Condition::Used, 25, true, 'cursor');

        self::assertSame('genre=FICTION&condition=USED&limit=25&signed=true&after=cursor', parse_url($this->httpClient->requests[0]->url, \PHP_URL_QUERY));
    }

    public function testReadsAResponseWithNestedModels(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => [self::BOOK + [
            'subtitle' => 'A life',
            'price' => 12,
            'condition' => 'USED',
            'translations' => ['fr' => ['Poche', 'Relié'], 'de' => []],
        ]]]);

        $book = $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction)->items[0];

        self::assertSame(320, $book->pages);
        self::assertFalse($book->signed);
        self::assertSame('Ada', $book->author->name);
        self::assertSame(Genre::NonFiction->value, $book->genre);
        self::assertSame(['history', 'science'], $book->tags);
        self::assertSame('A life', $book->subtitle);
        self::assertSame(12.0, $book->price);
        self::assertSame('USED', $book->condition);
        self::assertSame(['fr' => ['Poche', 'Relié'], 'de' => []], $book->translations);
    }

    public function testLeavesAnAbsentOptionalFieldNull(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => [self::BOOK + ['subtitle' => null]]]);

        $book = $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction)->items[0];

        self::assertNull($book->subtitle);
        self::assertNull($book->price);
        self::assertNull($book->condition);
        self::assertNull($book->translations);
    }

    public function testSendsAJsonBodyAndReadsACreatedResponse(): void
    {
        $this->httpClient->willReturnJson(201, self::BOOK);

        $book = $this->client->books->addBook(self::SHELF_ID, new NewBook('Notes', Genre::NonFiction, new Author('Ada')));

        $request = $this->httpClient->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame('{"title":"Notes","genre":"NON_FICTION","author":{"name":"Ada"}}', $request->body);
        self::assertSame(self::BOOK['id'], $book->id);
    }

    public function testLeavesAnOptionalBodyFieldOut(): void
    {
        $this->httpClient->willReturnJson(201, self::BOOK);

        $this->client->books->addBook(self::SHELF_ID, new NewBook('Notes', Genre::Fiction));

        self::assertSame('{"title":"Notes","genre":"FICTION"}', $this->httpClient->requests[0]->body);
    }

    public function testSendsTheBodyAgainAfterARefresh(): void
    {
        $this->httpClient->willReturnJson(401, ['code' => 'UNAUTHORIZED']);
        $this->httpClient->willReturnJson(200, ['access_token' => 'new-access-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 3600, 'scope' => 'books:write']);
        $this->httpClient->willReturnJson(201, self::BOOK);

        $this->client->books->addBook(self::SHELF_ID, new NewBook('Notes', Genre::Fiction));

        $again = $this->httpClient->requests[2];
        self::assertSame('Bearer new-access-token', $again->headers['Authorization']);
        self::assertSame('{"title":"Notes","genre":"FICTION"}', $again->body);
    }

    public function testEncodesEachPathParameterIntoOneSegmentAndAcceptsAnEmptyResponse(): void
    {
        $this->httpClient->willReturn(204, '');

        $this->client->shelves->removeBook('a/b', 'c?d');

        $request = $this->httpClient->requests[0];
        self::assertSame('DELETE', $request->method);
        self::assertSame('https://api.example.test/v1/shelves/a%2Fb/books/c%3Fd', $request->url);
    }

    public function testWritesAnEmptyMapAsAJsonObject(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => [self::BOOK + ['translations' => []]]]);

        $book = $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction)->items[0];

        self::assertStringContainsString('"translations":{}', json_encode($book, \JSON_THROW_ON_ERROR));
    }

    public function testReadsAndWritesTheVariantOfAOneOfSchema(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => [self::BOOK + ['cover' => ['kind' => 'hardcover', 'jacket' => true]]]]);

        $book = $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction)->items[0];

        self::assertInstanceOf(Hardcover::class, $book->cover);
        self::assertTrue($book->cover->jacket);
        self::assertStringContainsString('"cover":{"kind":"hardcover","jacket":true}', json_encode($book, \JSON_THROW_ON_ERROR));
    }

    public function testKeepsAResponseEnumValueThatTheSdkDoesNotKnow(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => [array_merge(self::BOOK, ['genre' => 'POETRY'])]]);

        $book = $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction)->items[0];

        self::assertSame('POETRY', $book->genre);
    }

    public function testRefusesAnEnumValueItDoesNotKnowInAModelThatIsNotResponseOnly(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('"genre"');

        NewBook::fromArray(['title' => 'Notes', 'genre' => 'POETRY']);
    }

    public function testWritesAListWithoutItsKeys(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => [self::BOOK]]);
        $book = $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction)->items[0];

        $encoded = json_encode(new BookList([1 => $book]), \JSON_THROW_ON_ERROR); // @phpstan-ignore argument.type

        self::assertStringStartsWith('{"items":[{', $encoded);
    }

    public function testReadsAMapWithANumericKey(): void
    {
        $this->httpClient->willReturnJson(200, ['items' => [self::BOOK + ['translations' => ['2024' => ['Poche']]]]]);

        $book = $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction)->items[0];

        self::assertSame([2024 => ['Poche']], $book->translations);
    }

    public function testReportsTheStatusOfAResponseItCannotRead(): void
    {
        $this->httpClient->willReturnJson(201, ['id' => 1]);

        try {
            $this->client->books->addBook(self::SHELF_ID, new NewBook('Notes', Genre::Fiction));
            self::fail('The call did not throw.');
        } catch (UnexpectedResponseException $exception) {
            self::assertSame(201, $exception->statusCode);
            self::assertStringContainsString('"id"', $exception->getMessage());
        }
    }

    public function testReportsASuccessBodyThatIsNotAnObject(): void
    {
        $this->httpClient->willReturn(200, '"hello"');

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('"body" is not an object');

        $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routeChangingValues(): iterable
    {
        yield 'empty' => [''];
        yield 'the current segment' => ['.'];
        yield 'the parent segment' => ['..'];
    }

    #[DataProvider('routeChangingValues')]
    public function testRefusesAPathParameterThatChangesTheRoute(string $bookId): void
    {
        try {
            $this->client->shelves->removeBook(self::SHELF_ID, $bookId);
            self::fail('The call did not throw.');
        } catch (ValidationException $exception) {
            self::assertSame([['code' => 'INVALID_PATH_PARAMETER', 'path' => 'bookId']], $exception->errors);
            self::assertSame([], $this->httpClient->requests);
        }
    }

    public function testRefusesABodyThatIsNotValidUtf8(): void
    {
        try {
            $this->client->books->addBook(self::SHELF_ID, new NewBook("\xff", Genre::Fiction));
            self::fail('The call did not throw.');
        } catch (ValidationException $exception) {
            self::assertSame([['code' => 'NOT_JSON_ENCODABLE', 'path' => 'body']], $exception->errors);
        }
    }

    public function testReadsA400ErrorObjectAsAnApiError(): void
    {
        $this->httpClient->willReturnJson(400, ['code' => 'BAD_REQUEST', 'detail' => ['code' => 'c', 'path' => 'p']]);

        $this->expectException(ApiException::class);

        $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unreadableBooks(): iterable
    {
        yield 'a missing required field' => [['pages' => null], 'items[].pages'];
        yield 'a string in place of an integer' => [['pages' => '320'], 'items[].pages'];
        yield 'an integer in place of a boolean' => [['signed' => 0], 'items[].signed'];
        yield 'an integer in place of an enum value' => [['genre' => 1], 'items[].genre'];
        yield 'a list in place of a model' => [['author' => ['Ada']], 'items[].author'];
        yield 'a wrong field in a nested model' => [['author' => ['name' => 1]], 'items[].author.name'];
        yield 'a map in place of a list' => [['tags' => ['first' => 'history']], 'items[].tags'];
        yield 'a wrong item in a list' => [['tags' => ['history', 2]], 'items[].tags[]'];
        yield 'a string in place of a number' => [['price' => '12'], 'items[].price'];
        yield 'a list in place of a map' => [['translations' => ['Poche']], 'items[].translations'];
        yield 'a oneOf variant that the SDK does not know' => [['cover' => ['kind' => 'scroll']], 'items[].cover.kind'];
        yield 'a wrong field in a oneOf variant' => [['cover' => ['kind' => 'hardcover']], 'items[].cover.jacket'];
        yield 'a wrong item in a map' => [['translations' => ['fr' => 'Poche']], 'items[].translations[]'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('unreadableBooks')]
    public function testNamesTheResponseFieldItCannotRead(array $fields, string $path): void
    {
        $this->httpClient->willReturnJson(200, ['items' => [array_merge(self::BOOK, $fields)]]);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('"'.$path.'"');

        $this->client->books->listBooks(self::SHELF_ID, Genre::NonFiction);
    }
}
