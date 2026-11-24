<?php

declare(strict_types=1);

namespace Elevate\Tests\Auth;

use Elevate\Auth\ClientAssertionSigner;
use Elevate\Config;
use Elevate\Tests\FixedClock;
use Elevate\Tests\Keys;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientAssertionSignerTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    protected function setUp(): void
    {
        JWT::$timestamp = self::NOW;
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function privateKeys(): iterable
    {
        yield 'RSA' => [Keys::rsaPrivateKey(), 'RS256'];
        yield 'P-256' => [Keys::ecPrivateKey(), 'ES256'];
    }

    #[DataProvider('privateKeys')]
    public function testSignsAnAssertionThatThePublicKeyVerifies(string $privateKey, string $algorithm): void
    {
        $config = new Config('client-id', $privateKey, 'key-id', 'https://auth.example.test');
        $signer = new ClientAssertionSigner($config, FixedClock::at(self::NOW));

        $assertion = $signer->sign();

        $headers = new \stdClass();
        $claims = (array) JWT::decode($assertion, new Key(Keys::publicKey($privateKey), $algorithm), $headers);
        self::assertSame(['typ' => 'JWT', 'alg' => $algorithm, 'kid' => 'key-id'], (array) $headers);
        self::assertSame('client-id', $claims['iss']);
        self::assertSame('client-id', $claims['sub']);
        self::assertSame('https://auth.example.test/oauth2/token', $claims['aud']);
        self::assertSame(self::NOW, $claims['iat']);
        self::assertSame(self::NOW + 60, $claims['exp']);
    }

    public function testGivesEachAssertionItsOwnId(): void
    {
        $privateKey = Keys::ecPrivateKey();
        $key = new Key(Keys::publicKey($privateKey), 'ES256');
        $signer = new ClientAssertionSigner(new Config('client-id', $privateKey, 'key-id'), FixedClock::at(self::NOW));

        $first = (array) JWT::decode($signer->sign(), $key);
        $second = (array) JWT::decode($signer->sign(), $key);

        self::assertNotSame($first['jti'], $second['jti']);
    }
}
