<?php

declare(strict_types=1);

namespace Elevate\Tests\Auth;

use Elevate\Auth\Tokens;
use PHPUnit\Framework\TestCase;

final class TokensTest extends TestCase
{
    public function testOmitsTheTokensFromDebugOutput(): void
    {
        $tokens = new Tokens('secret-access-token', 'secret-refresh-token', new \DateTimeImmutable('@1800000000'), ['read:team']);

        $output = print_r($tokens, true);

        self::assertStringNotContainsString('secret-access-token', $output);
        self::assertStringNotContainsString('secret-refresh-token', $output);
        self::assertStringContainsString('read:team', $output);
    }
}
