<?php

declare(strict_types=1);

namespace Elevate\Tests\Http;

use Elevate\Http\CurlHttpClient;
use Elevate\Http\HttpClientException;
use Elevate\Http\Request;
use PHPUnit\Framework\TestCase;

final class CurlHttpClientTest extends TestCase
{
    /** @var resource */
    private static $server;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        self::$baseUrl = 'http://127.0.0.1:'.$port;
        $server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/server.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        self::$server = $server;

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @fsockopen('127.0.0.1', $port);
            if (false !== $connection) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }
        self::fail('The test server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
    }

    public function testSendsTheMethodTheHeadersAndTheBody(): void
    {
        $body = str_repeat('a=b&', 1000);

        $response = (new CurlHttpClient())->send(new Request(
            'POST',
            self::$baseUrl.'/v1/things?after=a%20b',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Authorization' => 'Bearer the-token'],
            $body,
        ));

        self::assertSame(200, $response->statusCode);
        $received = self::received($response->body);
        self::assertSame('POST', $received['method']);
        self::assertSame('/v1/things?after=a%20b', $received['uri']);
        self::assertSame($body, $received['body']);
        self::assertIsArray($received['headers']);
        self::assertSame('application/x-www-form-urlencoded', $received['headers']['Content-Type']);
        self::assertSame('Bearer the-token', $received['headers']['Authorization']);
        self::assertArrayNotHasKey('Expect', $received['headers']);
    }

    public function testSendsARequestWithoutABody(): void
    {
        $response = (new CurlHttpClient())->send(new Request('DELETE', self::$baseUrl.'/v1/things/1'));

        $received = self::received($response->body);
        self::assertSame('DELETE', $received['method']);
        self::assertSame('', $received['body']);
    }

    public function testReturnsAnErrorResponse(): void
    {
        $response = (new CurlHttpClient())->send(new Request('GET', self::$baseUrl.'/?status=503'));

        self::assertSame(503, $response->statusCode);
        self::assertSame('GET', self::received($response->body)['method']);
    }

    public function testReturnsTheResponseHeaders(): void
    {
        $response = (new CurlHttpClient())->send(new Request('GET', self::$baseUrl.'/'));

        self::assertSame('application/json', $response->headers['content-type']);
        self::assertSame('"the-etag"', $response->header('ETag'));
        self::assertSame('first, second', $response->header('x-repeated'));
        self::assertNull($response->header('X-Absent'));
    }

    public function testDoesNotFollowARedirect(): void
    {
        $response = (new CurlHttpClient())->send(new Request('GET', self::$baseUrl.'/redirect'));

        self::assertSame(302, $response->statusCode);
    }

    public function testThrowsWhenNoResponseArrives(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/curl error \d+/');

        (new CurlHttpClient())->send(new Request('GET', 'http://127.0.0.1:'.self::freePort().'/'));
    }

    public function testRefusesAnotherProtocol(): void
    {
        $this->expectException(HttpClientException::class);

        (new CurlHttpClient())->send(new Request('GET', 'file:///etc/hostname'));
    }

    public function testRefusesAHeaderWithALineBreakAndKeepsItsValueOutOfTheMessage(): void
    {
        try {
            (new CurlHttpClient())->send(new Request('GET', self::$baseUrl.'/', ['Authorization' => "Bearer the-token\r\nX-Injected: 1"]));
            self::fail('The client did not throw.');
        } catch (HttpClientException $exception) {
            self::assertStringContainsString('Authorization', $exception->getMessage());
            self::assertStringNotContainsString('the-token', $exception->getMessage());
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function received(string $body): array
    {
        $received = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($received);

        return $received;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
