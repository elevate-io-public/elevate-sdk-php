<?php

declare(strict_types=1);

namespace Elevate\Tests;

use Elevate\Config;
use Elevate\Exception\InvalidConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsToTheProductionAuthorizationServer(): void
    {
        $config = new Config('client-id', Keys::ecPrivateKey(), 'key-id');

        self::assertSame('https://auth.elevate.io/oauth2/token', $config->tokenUrl());
    }

    public function testIgnoresATrailingSlashOnTheAuthorizationServerUrl(): void
    {
        $config = new Config('client-id', Keys::ecPrivateKey(), 'key-id', 'https://auth.example.test/');

        self::assertSame('https://auth.example.test/oauth2/token', $config->tokenUrl());
    }

    public function testDefaultsToTheProductionApi(): void
    {
        $config = new Config('client-id', Keys::ecPrivateKey(), 'key-id');

        self::assertSame('https://api.elevate.io', $config->apiUrl);
    }

    public function testIgnoresATrailingSlashOnTheApiUrl(): void
    {
        $config = new Config('client-id', Keys::ecPrivateKey(), 'key-id', apiUrl: 'https://api.example.test/');

        self::assertSame('https://api.example.test', $config->apiUrl);
    }

    public function testRejectsAnApiUrlThatIsNotHttps(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The API URL is not an https URL.');

        new Config('client-id', Keys::ecPrivateKey(), 'key-id', apiUrl: 'http://api.example.test');
    }

    public function testSignsWithRs256ForAnRsaKey(): void
    {
        $config = new Config('client-id', Keys::rsaPrivateKey(), 'key-id');

        self::assertSame('RS256', $config->signingAlgorithm);
    }

    public function testSignsWithEs256ForAP256Key(): void
    {
        $config = new Config('client-id', Keys::ecPrivateKey(), 'key-id');

        self::assertSame('ES256', $config->signingAlgorithm);
    }

    public function testReturnsThePrivateKey(): void
    {
        $privateKey = Keys::ecPrivateKey();

        $config = new Config('client-id', $privateKey, 'key-id');

        self::assertSame($privateKey, $config->privateKey());
    }

    public function testOmitsThePrivateKeyFromDebugOutput(): void
    {
        $config = new Config('client-id', Keys::ecPrivateKey(), 'key-id');

        ob_start();
        var_dump($config);
        $dump = print_r($config, true).ob_get_clean();

        self::assertStringContainsString('client-id', $dump);
        self::assertStringNotContainsString('PRIVATE KEY', $dump);
        self::assertStringNotContainsString('privateKey', $dump);
    }

    public function testOmitsThePrivateKeyFromExportedOutput(): void
    {
        $config = new Config('client-id', Keys::ecPrivateKey(), 'key-id');

        $export = var_export($config, true).print_r((array) $config, true);

        self::assertStringContainsString('client-id', $export);
        self::assertStringNotContainsString('PRIVATE KEY', $export);
    }

    public function testRefusesSerialization(): void
    {
        $config = new Config('client-id', Keys::ecPrivateKey(), 'key-id');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Serialization');

        serialize($config);
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function invalidArguments(): iterable
    {
        $privateKey = Keys::ecPrivateKey();

        yield 'empty client id' => ['', $privateKey, 'key-id', 'https://auth.example.test', 'client id'];
        yield 'empty key id' => ['client-id', $privateKey, '', 'https://auth.example.test', 'key id'];
        yield 'http URL' => ['client-id', $privateKey, 'key-id', 'http://auth.example.test', 'authorization server URL'];
        yield 'URL without a scheme' => ['client-id', $privateKey, 'key-id', 'auth.example.test', 'authorization server URL'];
        yield 'public key' => ['client-id', Keys::publicKey($privateKey), 'key-id', 'https://auth.example.test', 'private key'];
        yield 'P-384 key' => ['client-id', Keys::ecPrivateKey('secp384r1'), 'key-id', 'https://auth.example.test', 'P-256'];
        yield 'not a key' => ['client-id', 'not a key', 'key-id', 'https://auth.example.test', 'private key'];
    }

    #[DataProvider('invalidArguments')]
    public function testRejectsInvalidArguments(string $clientId, string $privateKey, string $keyId, string $authUrl, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        new Config($clientId, $privateKey, $keyId, $authUrl);
    }
}
