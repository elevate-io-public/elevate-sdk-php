<?php

declare(strict_types=1);

namespace Elevate\SystemTests;

use Elevate\Api\ApiClient;
use Elevate\Auth\Connection;
use Elevate\Auth\TokenClient;
use Elevate\Auth\Tokens;
use Elevate\Clock;
use Elevate\Config;
use Elevate\Exception\TokenRequestException;
use Elevate\SystemTests\Support\FakeElevateServer;
use Elevate\Tests\Keys;
use PHPUnit\Framework\TestCase;

/**
 * Runs the whole SDK, with its own HTTP client, against the fake of elevate.io in a server process.
 */
abstract class SystemTestCase extends TestCase
{
    protected const string TEAM_ID = '851c9c60-ac5e-440e-8e77-bdb75071f6b4';
    protected const string OTHER_TEAM_ID = '5d0f3c1a-27f3-4a43-b0f4-6f0a1c7d9e02';
    protected const string MEDIA_ID = '9c4a7e52-1b7d-4f0e-a3f6-2d8b5e6c7a10';
    protected const string REDIRECT_URI = 'https://integrator.example.test/callback';
    protected const array SCOPES = ['select:team', 'teams:read', 'projects:read', 'projects:write', 'media:write'];
    protected const int NOW = 1_800_000_000;
    protected const int LIFETIME = 3600;

    protected Config $config;
    protected FakeElevateServer $elevate;
    protected TokenClient $tokenClient;
    /** @var list<Tokens> */
    protected array $stored;

    protected function setUp(): void
    {
        $this->start(Keys::ecPrivateKey());
    }

    protected function tearDown(): void
    {
        if (!isset($this->elevate)) {
            return;
        }
        $reports = [];
        foreach ([$this->elevate->violations(...), $this->elevate->faults(...)] as $read) {
            try {
                $reports = [...$reports, ...$read()];
            } catch (\RuntimeException $exception) {
                $reports[] = $exception->getMessage();
            }
        }
        if ([] === $reports) {
            return;
        }
        $message = \sprintf("The fake of elevate.io reports in %s:\n%s", $this->toString(), implode("\n", $reports));
        if ($this->status()->isSuccess()) {
            self::fail($message);
        }
        // Writes the report to the standard error stream: PHPUnit drops a failure of tearDown() after a failed test.
        fwrite(\STDERR, "\n".$message."\n");
    }

    protected function start(string $privateKey): void
    {
        $this->elevate = FakeElevateServer::running();
        $this->config = new Config('client-id', $privateKey, 'key-id', $this->elevate->url, $this->elevate->url);
        $this->elevate->reset($this->config, Keys::publicKey($privateKey), self::NOW, self::LIFETIME);
        $this->tokenClient = new TokenClient($this->config, clock: $this->elevate);
        $this->stored = [];
    }

    /**
     * Runs the connect flow as the integrator's backend does, with the fake as the user's browser.
     *
     * @param list<string> $scopes
     */
    protected function connect(array $scopes = self::SCOPES): Connection
    {
        $request = $this->tokenClient->authorizationRequest(self::REDIRECT_URI, $scopes);
        $redirect = $this->elevate->approve($request->url, self::TEAM_ID);

        self::assertStringStartsWith(self::REDIRECT_URI.'?', $redirect);
        $query = self::query($redirect);
        self::assertSame($request->state, $query['state']);

        return $this->tokenClient->exchangeCode($query['code'], self::REDIRECT_URI, $request->codeVerifier);
    }

    protected function apiClient(Tokens $tokens, ?Clock $clock = null): ApiClient
    {
        return new ApiClient($this->config, $tokens, function (Tokens $tokens): void {
            $this->stored[] = $tokens;
        }, clock: $clock ?? $this->elevate);
    }

    protected function assertConnectionIsRevoked(ApiClient $apiClient): void
    {
        try {
            $apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call succeeded.');
        } catch (TokenRequestException $exception) {
            self::assertTrue($exception->isInvalidGrant());
            self::assertSame([], $this->stored);
        }
    }

    /**
     * @return array{code: string, state: string}
     */
    protected static function query(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $code = $query['code'] ?? null;
        $state = $query['state'] ?? null;
        self::assertIsString($code);
        self::assertIsString($state);

        return ['code' => $code, 'state' => $state];
    }
}
