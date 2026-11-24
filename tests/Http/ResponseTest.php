<?php

declare(strict_types=1);

namespace Elevate\Tests\Http;

use Elevate\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testFindsAHeaderInAnyCase(): void
    {
        $response = new Response(200, ['ETag' => '"the-etag"'], '');

        self::assertSame(['etag' => '"the-etag"'], $response->headers);
        self::assertSame('"the-etag"', $response->header('Etag'));
        self::assertNull($response->header('Location'));
    }

    public function testKeepsTheHeadersAndTheBodyOutOfADump(): void
    {
        $response = new Response(200, ['Set-Cookie' => 'the-cookie'], 'the-body');

        self::assertSame(['statusCode' => 200], $response->__debugInfo());
    }
}
