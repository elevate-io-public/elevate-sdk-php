<?php

declare(strict_types=1);

namespace Elevate\Tests\SystemTests;

use Elevate\Http\Request;
use Elevate\Http\Response;
use Elevate\SystemTests\Support\ContractViolation;
use Elevate\SystemTests\Support\Operation;
use Elevate\SystemTests\Support\Specification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecificationTest extends TestCase
{
    private const string SPECIFICATION = <<<'YAML'
        openapi: 3.0.3
        info:
          title: Shelves
          version: 1.0.0
        paths:
          /shelves/{shelfId}/books:
            post:
              operationId: addBook
              security:
                - oauth2:
                    - books:write
              parameters:
                - $ref: '#/components/parameters/ShelfId'
                - in: query
                  name: limit
                  schema:
                    type: integer
                    maximum: 10
              requestBody:
                required: true
                content:
                  application/json:
                    schema:
                      $ref: '#/components/schemas/Book'
              responses:
                '201':
                  description: The book
                  content:
                    application/json:
                      schema:
                        $ref: '#/components/schemas/Book'
                '204':
                  description: Nothing
                '409':
                  description: A book that no schema allows
                  content:
                    application/json:
                      schema:
                        $ref: '#/components/schemas/Unbuildable'
          /health:
            get:
              operationId: getHealth
              security: []
              responses:
                '204':
                  description: Healthy
        components:
          securitySchemes:
            oauth2:
              type: http
              scheme: bearer
          parameters:
            ShelfId:
              in: path
              name: shelfId
              required: true
              schema:
                type: string
                format: uuid
          schemas:
            Book:
              type: object
              required:
                - title
              properties:
                title:
                  type: string
                  minLength: 6
                pages:
                  type: integer
                  minimum: 10
                copies:
                  type: integer
                genre:
                  type: string
                  enum:
                    - CRIME
                    - POETRY
                page:
                  type: string
                  format: uri
                signed:
                  type: boolean
                authors:
                  type: array
                  items:
                    type: string
                notes:
                  type: object
                  additionalProperties:
                    type: string
                cover:
                  type: object
                  properties:
                    id:
                      type: string
                      format: uuid
            Unbuildable:
              type: object
              properties:
                colour:
                  type: string
                  pattern: ^#[0-9a-f]{6}$
        YAML;

    private const string URL = 'https://shelves.example.test/shelves/851c9c60-ac5e-440e-8e77-bdb75071f6b4/books';

    private Specification $specification;
    private Operation $addBook;

    protected function setUp(): void
    {
        $this->specification = Specification::fromYaml(self::SPECIFICATION);
        $match = $this->specification->match('POST', '/shelves/851c9c60-ac5e-440e-8e77-bdb75071f6b4/books');
        self::assertNotNull($match);
        $this->addBook = $match[0];
    }

    public function testFindsTheOperationOfARequest(): void
    {
        $match = $this->specification->match('POST', '/shelves/a%20shelf/books');

        self::assertNotNull($match);
        [$operation, $parameters] = $match;
        self::assertSame('addBook', $operation->id);
        self::assertSame(['shelfId' => 'a shelf'], $parameters);
        self::assertSame(['books:write'], $this->specification->scopes($operation));
        self::assertSame(201, $this->specification->successStatus($operation));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownRequests(): iterable
    {
        yield 'another method' => ['GET', '/shelves/1/books'];
        yield 'another path' => ['POST', '/shelves/1/films'];
        yield 'a longer path' => ['POST', '/shelves/1/books/2'];
    }

    #[DataProvider('unknownRequests')]
    public function testFindsNoOperationForAnUnknownRequest(string $method, string $path): void
    {
        self::assertNull($this->specification->match($method, $path));
    }

    public function testReadsAnOperationWithoutAnAccessToken(): void
    {
        $match = $this->specification->match('GET', '/health');

        self::assertNotNull($match);
        self::assertNull($this->specification->scopes($match[0]));
    }

    public function testAcceptsARequestThatTheSpecificationDescribes(): void
    {
        self::assertSame([], $this->specification->violations($this->addBook, self::request(['title' => 'Dune II', 'pages' => 412], '?limit=10')));
    }

    /**
     * @return iterable<string, array{Request, string, string}>
     */
    public static function invalidRequests(): iterable
    {
        // The library reports a format under the keyword "type".
        yield 'a path parameter outside the format' => [new Request('POST', 'https://shelves.example.test/shelves/top/books', self::HEADERS, '{"title":"Dune II"}'), 'type', 'shelfId'];
        yield 'a query parameter above the maximum' => [self::request(['title' => 'Dune II'], '?limit=11'), 'maximum', 'limit'];
        yield 'a missing required property' => [self::request(['pages' => 412]), 'required', 'title'];
        yield 'a short string' => [self::request(['title' => 'Dune']), 'minLength', 'title'];
        yield 'another type in an array' => [self::request(['title' => 'Dune II', 'authors' => ['Frank', 3]]), 'type', 'authors.1'];
    }

    #[DataProvider('invalidRequests')]
    public function testReportsTheKeywordThatARequestFails(Request $request, string $code, string $path): void
    {
        $match = $this->specification->match('POST', (string) parse_url($request->url, \PHP_URL_PATH));
        self::assertNotNull($match);

        self::assertSame([['code' => $code, 'path' => $path]], $this->specification->violations($match[0], $request));
    }

    /**
     * @return iterable<string, array{Request, string}>
     */
    public static function undescribedRequests(): iterable
    {
        yield 'an unknown property' => [self::request(['title' => 'Dune II', 'isbn' => '0']), 'isbn'];
        yield 'an unknown property of a nested object' => [self::request(['title' => 'Dune II', 'cover' => ['colour' => 'red']]), 'colour'];
        yield 'an unknown query parameter' => [self::request(['title' => 'Dune II'], '?after=1'), '"after"'];
        yield 'a body that is not JSON' => [new Request('POST', self::URL, ['Authorization' => 'Bearer token', 'Content-Type' => 'text/plain'], 'Dune II'), 'text/plain'];
    }

    #[DataProvider('undescribedRequests')]
    public function testRefusesARequestThatTheSpecificationDoesNotDescribe(Request $request, string $message): void
    {
        $this->expectException(ContractViolation::class);
        $this->expectExceptionMessage($message);

        $this->specification->violations($this->addBook, $request);
    }

    public function testRefusesABodyWhereTheOperationTakesNone(): void
    {
        $match = $this->specification->match('GET', '/health');
        self::assertNotNull($match);

        $this->expectException(ContractViolation::class);
        $this->expectExceptionMessage('takes no request body');

        $this->specification->violations($match[0], new Request('GET', 'https://shelves.example.test/health', [], '{}'));
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function undescribedResponses(): iterable
    {
        yield 'a status that the operation does not declare' => [new Response(404, [], '')];
        yield 'an unknown property' => [new Response(201, ['Content-Type' => 'application/json'], '{"title":"Dune II","isbn":"0"}')];
        yield 'a value that the schema refuses' => [new Response(201, ['Content-Type' => 'application/json'], '{"title":"Dune"}')];
    }

    #[DataProvider('undescribedResponses')]
    public function testRefusesAResponseThatTheSpecificationDoesNotDescribe(Response $response): void
    {
        $this->expectException(ContractViolation::class);

        $this->specification->check($this->addBook, $response);
    }

    public function testBuildsAResponseWithAnotherValueForEachProperty(): void
    {
        $example = $this->specification->example($this->addBook, 201);

        self::assertSame([
            'title' => 'titlet',
            'pages' => 11,
            'copies' => 2,
            'genre' => 'CRIME',
            'page' => 'https://elevate.example.test/page',
            'signed' => true,
            'authors' => ['authors'],
            'notes' => ['key' => 'notes'],
            'cover' => ['id' => \sprintf('%08x-0000-4000-8000-000000000000', crc32('id'))],
        ], $example);
        $this->specification->check($this->addBook, new Response(201, ['Content-Type' => 'application/json'], json_encode($example, \JSON_THROW_ON_ERROR)));
    }

    public function testBuildsNoBodyForAResponseWithoutContent(): void
    {
        self::assertNull($this->specification->example($this->addBook, 204));
    }

    public function testRefusesToBuildAValueForASchemaThatItDoesNotSupport(): void
    {
        $this->expectException(ContractViolation::class);
        $this->expectExceptionMessage('pattern');

        $this->specification->example($this->addBook, 409);
    }

    public function testBuildsAResponseThatEveryOperationOfThePinnedSpecificationAccepts(): void
    {
        $specification = Specification::pinned();
        $checked = 0;
        foreach ($specification->operations() as $operation) {
            if (null === $specification->scopes($operation)) {
                continue;
            }
            $statusCode = $specification->successStatus($operation);
            $example = $specification->example($operation, $statusCode);
            if (null === $example) {
                continue;
            }
            $specification->check($operation, new Response($statusCode, ['Content-Type' => 'application/json'], json_encode($example, \JSON_THROW_ON_ERROR)));
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }

    private const array HEADERS = ['Authorization' => 'Bearer token', 'Content-Type' => 'application/json'];

    /**
     * @param array<string, mixed> $book
     */
    private static function request(array $book, string $query = ''): Request
    {
        return new Request('POST', self::URL.$query, self::HEADERS, json_encode($book, \JSON_THROW_ON_ERROR));
    }
}
