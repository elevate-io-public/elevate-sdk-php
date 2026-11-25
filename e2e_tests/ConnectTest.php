<?php

declare(strict_types=1);

namespace Elevate\E2eTests;

use Elevate\Api\ApiClient;
use Elevate\Auth\TokenClient;
use Elevate\Auth\Tokens;
use Elevate\Config;
use Elevate\E2eTests\Support\ElevateUser;
use Elevate\Exception\ApiException;
use Elevate\Exception\ElevateException;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use PHPUnit\Framework\TestCase;

/**
 * Runs the SDK against a deployed elevate.io, as a test user who connects a team to a test client.
 */
final class ConnectTest extends TestCase
{
    private const array SCOPES = ['select:team', 'teams:read'];

    private Config $config;
    private ?Tokens $tokens = null;

    protected function setUp(): void
    {
        $this->config = new Config(
            self::env('ELEVATE_E2E_CLIENT_ID'),
            self::env('ELEVATE_E2E_PRIVATE_KEY'),
            self::env('ELEVATE_E2E_KEY_ID'),
            self::env('ELEVATE_E2E_AUTH_URL'),
            self::env('ELEVATE_E2E_API_URL'),
        );
    }

    /**
     * Revokes the grant of a test that stopped early: elevate.io refuses a second connection of the same team.
     */
    protected function tearDown(): void
    {
        if (null === $this->tokens) {
            return;
        }
        try {
            $this->apiClient()->auth->revokeGrant();
        } catch (\Throwable $exception) {
            // Writes a fixed message to the standard error stream: PHPUnit drops a failure of tearDown() after a
            // failed test, and the message of the exception can name the host.
            fwrite(\STDERR, \sprintf("\nThe revoke of the grant of the stopped test failed with %s. The team stays connected until the grant is revoked.\n", $exception::class));
        }
    }

    public function testConnectsATeamReadsItRefreshesAndRevokes(): void
    {
        try {
            $this->connectReadRefreshAndRevoke();
        } catch (ElevateException $exception) {
            // Fails with a message of its own: PHPUnit prints the message of an uncaught exception, and the messages
            // of the SDK carry text of the response.
            self::fail(self::describe($exception));
        }
    }

    private function connectReadRefreshAndRevoke(): void
    {
        $redirectUri = self::env('ELEVATE_E2E_REDIRECT_URI');
        $tokenClient = new TokenClient($this->config);
        $user = new ElevateUser(
            $this->config->authUrl,
            self::env('ELEVATE_E2E_IDENTITY_API_URL'),
            self::env('ELEVATE_E2E_USER_EMAIL'),
            self::env('ELEVATE_E2E_USER_PASSWORD'),
        );

        $request = $tokenClient->authorizationRequest($redirectUri, self::SCOPES);
        $connected = $user->connect($request->url);

        parse_str((string) parse_url($connected['redirectUrl'], \PHP_URL_QUERY), $query);
        $code = $query['code'] ?? null;
        $state = $query['state'] ?? null;
        if (!\is_string($code)) {
            self::fail('The redirect has no code.');
        }

        // Exchanges the code before it checks the redirect: tearDown() needs the tokens to revoke the grant.
        $connection = $tokenClient->exchangeCode($code, $redirectUri, $request->codeVerifier);
        $this->tokens = $connection->tokens;

        self::assertTrue(str_starts_with($connected['redirectUrl'], $redirectUri.'?'), 'The redirect does not go to the redirect URI.');
        self::assertTrue(\is_string($state) && hash_equals($request->state, $state), 'The redirect does not carry the state of the request.');
        self::assertSame('team', $connection->resourceType);
        self::assertTrue($connected['teamId'] === $connection->resourceId, 'The connection is not for the connected team.');
        // Expects no `select:team`: the consent uses it to select the team and grants no access with it.
        self::assertEqualsCanonicalizing(['openid', 'offline_access', 'teams:read'], $connection->tokens->scopes);
        $this->assertReadsTheTeam($connected['teamId']);

        $refreshed = $tokenClient->refresh($connection->tokens->refreshToken);
        $this->tokens = $refreshed;

        self::assertFalse(hash_equals($connection->tokens->refreshToken, $refreshed->refreshToken), 'The refresh returned the same refresh token.');
        self::assertFalse(hash_equals($connection->tokens->accessToken, $refreshed->accessToken), 'The refresh returned the same access token.');
        $this->assertReadsTheTeam($connected['teamId']);

        $this->apiClient()->auth->revokeGrant();
        $this->tokens = null;

        try {
            // Keeps the tokens of a refresh that succeeds: the grant is still live, and tearDown() revokes it.
            $this->tokens = $tokenClient->refresh($refreshed->refreshToken);
            self::fail('The refresh succeeded after the revoke.');
        } catch (TokenRequestException $exception) {
            self::assertTrue($exception->isInvalidGrant(), 'The refresh failed after the revoke with an error other than invalid_grant.');
        }
    }

    private function assertReadsTheTeam(string $teamId): void
    {
        self::assertTrue($teamId === $this->apiClient()->teams->getTeam($teamId)->id, 'The API returned another team.');
    }

    private function apiClient(): ApiClient
    {
        self::assertNotNull($this->tokens);

        return new ApiClient($this->config, $this->tokens, function (Tokens $tokens): void {
            $this->tokens = $tokens;
        });
    }

    /**
     * @return string the class, the status, the error code and the line of this file that made the call
     */
    private static function describe(ElevateException $exception): string
    {
        $code = match (true) {
            $exception instanceof TokenRequestException => $exception->error,
            $exception instanceof ApiException => $exception->errorCode,
            default => null,
        };
        $status = match (true) {
            $exception instanceof TokenRequestException, $exception instanceof ApiException, $exception instanceof TransportException, $exception instanceof UnexpectedResponseException => $exception->statusCode,
            default => null,
        };
        $line = null;
        foreach ($exception->getTrace() as $frame) {
            if (__FILE__ === ($frame['file'] ?? null)) {
                $line = $frame['line'] ?? null;
                break;
            }
        }

        return \sprintf(
            'The SDK threw %s (status %s, error code %s) from the call at line %s.',
            $exception::class,
            $status ?? 'none',
            // Prints a code only in the form of an error code: elevate.io sets its value.
            null === $code ? 'none' : (1 === preg_match('/^[A-Za-z_]{1,40}$/', $code) ? $code : 'unknown'),
            $line ?? 'unknown',
        );
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (!\is_string($value) || '' === $value) {
            self::fail(\sprintf('Set %s.', $name));
        }

        return $value;
    }
}
