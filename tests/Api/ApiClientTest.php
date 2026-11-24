<?php

declare(strict_types=1);

namespace Elevate\Tests\Api;

use Elevate\Api\ApiClient;
use Elevate\Auth\Tokens;
use Elevate\Config;
use Elevate\Exception\ApiException;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;
use Elevate\Http\HttpClientException;
use Elevate\Tests\FixedClock;
use Elevate\Tests\Keys;
use Elevate\Tests\RecordingHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    private const string TEAM_ID = '851c9c60-ac5e-440e-8e77-bdb75071f6b4';

    private const int NOW = 1_800_000_000;
    private const array TEAM = ['id' => self::TEAM_ID, 'name' => 'Newsroom'];
    private const array UNAUTHORIZED = ['code' => 'UNAUTHORIZED'];

    private static Config $config;
    private RecordingHttpClient $httpClient;
    private ApiClient $apiClient;
    /** @var list<Tokens> */
    private array $stored;

    public static function setUpBeforeClass(): void
    {
        self::$config = new Config('client-id', Keys::ecPrivateKey(), 'key-id', 'https://auth.example.test', 'https://api.example.test');
    }

    protected function setUp(): void
    {
        $this->httpClient = new RecordingHttpClient();
        $this->stored = [];
        $this->apiClient = $this->apiClient(self::tokens());
    }

    public function testReadsATeam(): void
    {
        $this->httpClient->willReturnJson(200, self::TEAM);

        $team = $this->apiClient->teams->getTeam(self::TEAM_ID);

        self::assertSame(self::TEAM_ID, $team->id);
        self::assertSame('Newsroom', $team->name);
        $request = $this->httpClient->requests[0];
        self::assertSame('GET', $request->method);
        self::assertSame('https://api.example.test/v1/teams/'.self::TEAM_ID, $request->url);
        self::assertSame('Bearer the-access-token', $request->headers['Authorization']);
        self::assertCount(1, $this->httpClient->requests);
        self::assertSame([], $this->stored);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function expiredLifetimes(): iterable
    {
        yield 'expired' => [-1];
        yield 'expires now' => [0];
        yield 'expires within the margin' => [30];
    }

    #[DataProvider('expiredLifetimes')]
    public function testRefreshesAnExpiredAccessTokenBeforeTheCall(int $secondsToExpiry): void
    {
        $apiClient = $this->apiClient(self::tokens(secondsToExpiry: $secondsToExpiry));
        $this->httpClient->willReturnJson(200, self::tokenResponse());
        $this->httpClient->willReturnJson(200, self::TEAM);

        $apiClient->teams->getTeam(self::TEAM_ID);

        [$refresh, $call] = $this->httpClient->requests;
        self::assertSame('https://auth.example.test/oauth2/token', $refresh->url);
        parse_str($refresh->body, $form);
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('the-refresh-token', $form['refresh_token']);
        self::assertSame('Bearer new-access-token', $call->headers['Authorization']);
        self::assertCount(1, $this->stored);
        self::assertSame('new-refresh-token', $this->stored[0]->refreshToken);
        self::assertSame(self::NOW + 3600, $this->stored[0]->expiresAt->getTimestamp());
        self::assertSame($this->stored[0], $apiClient->tokens());
    }

    public function testKeepsAnAccessTokenThatExpiresAfterTheMargin(): void
    {
        $apiClient = $this->apiClient(self::tokens(secondsToExpiry: 31));
        $this->httpClient->willReturnJson(200, self::TEAM);

        $apiClient->teams->getTeam(self::TEAM_ID);

        self::assertCount(1, $this->httpClient->requests);
    }

    public function testRefreshesAndCallsAgainWhenTheApiRefusesTheAccessToken(): void
    {
        $this->httpClient->willReturnJson(401, self::UNAUTHORIZED);
        $this->httpClient->willReturnJson(200, self::tokenResponse());
        $this->httpClient->willReturnJson(200, self::TEAM);

        $team = $this->apiClient->teams->getTeam(self::TEAM_ID);

        self::assertSame('Newsroom', $team->name);
        self::assertSame('Bearer the-access-token', $this->httpClient->requests[0]->headers['Authorization']);
        self::assertSame('Bearer new-access-token', $this->httpClient->requests[2]->headers['Authorization']);
        self::assertCount(1, $this->stored);
    }

    public function testDoesNotRefreshTwiceForOneCall(): void
    {
        $apiClient = $this->apiClient(self::tokens(secondsToExpiry: 0));
        $this->httpClient->willReturnJson(200, self::tokenResponse());
        $this->httpClient->willReturnJson(401, self::UNAUTHORIZED);

        try {
            $apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->isInvalidToken());
            self::assertCount(2, $this->httpClient->requests);
            self::assertCount(1, $this->stored);
        }
    }

    public function testUsesTheRefreshedTokensForTheNextCall(): void
    {
        $this->httpClient->willReturnJson(401, self::UNAUTHORIZED);
        $this->httpClient->willReturnJson(200, self::tokenResponse());
        $this->httpClient->willReturnJson(200, self::TEAM);
        $this->httpClient->willReturnJson(200, self::TEAM);

        $this->apiClient->teams->getTeam(self::TEAM_ID);
        $this->apiClient->teams->getTeam(self::TEAM_ID);

        self::assertCount(4, $this->httpClient->requests);
        self::assertSame('Bearer new-access-token', $this->httpClient->requests[3]->headers['Authorization']);
    }

    public function testReportsARevokedConnectionWithoutStoringTokens(): void
    {
        $this->httpClient->willReturnJson(401, self::UNAUTHORIZED);
        $this->httpClient->willReturnJson(400, ['error' => 'invalid_grant']);

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (TokenRequestException $exception) {
            self::assertTrue($exception->isInvalidGrant());
            self::assertSame([], $this->stored);
        }
    }

    public function testEncodesTheTeamIdIntoOnePathSegment(): void
    {
        $this->httpClient->willReturnJson(200, self::TEAM);

        $this->apiClient->teams->getTeam('a/b?c');

        self::assertSame('https://api.example.test/v1/teams/a%2Fb%3Fc', $this->httpClient->requests[0]->url);
    }

    public function testReportsAnApiError(): void
    {
        $this->httpClient->willReturnJson(403, [
            'code' => 'PLAN_NOT_ELIGIBLE',
            'message' => 'The plan does not include the integrator.',
            'actionUrl' => 'https://elevate.example.test/plans',
        ]);

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (ApiException $exception) {
            self::assertSame('PLAN_NOT_ELIGIBLE', $exception->errorCode);
            self::assertSame('The plan does not include the integrator.', $exception->detail);
            self::assertSame('https://elevate.example.test/plans', $exception->actionUrl);
            self::assertSame(403, $exception->statusCode);
            self::assertTrue($exception->isPlanNotEligible());
            self::assertFalse($exception->isNotGranted());
            self::assertFalse($exception->isInvalidToken());
        }
    }

    public function testReportsAResourceThatTheConnectionDoesNotCover(): void
    {
        $this->httpClient->willReturnJson(403, ['code' => 'INTEGRATOR_NOT_GRANTED']);

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->isNotGranted());
            self::assertNull($exception->detail);
            self::assertNull($exception->actionUrl);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unauthorizedCodes(): iterable
    {
        yield 'the documented code' => ['INVALID_TOKEN'];
        yield 'another code' => ['UNAUTHORIZED'];
    }

    #[DataProvider('unauthorizedCodes')]
    public function testReportsAnAccessTokenThatTheApiStillRefusesAfterARefresh(string $code): void
    {
        $this->httpClient->willReturnJson(401, ['code' => $code]);
        $this->httpClient->willReturnJson(200, self::tokenResponse());
        $this->httpClient->willReturnJson(401, ['code' => $code]);

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->isInvalidToken());
            self::assertCount(3, $this->httpClient->requests);
        }
    }

    public function testReportsValidationErrors(): void
    {
        $this->httpClient->willReturn(400, '[{"code":"invalid_uuid","path":"teamId","detail":"x"},{"code":"unknown","path":"limit"}]');

        try {
            $this->apiClient->teams->getTeam('not-a-uuid');
            self::fail('The call did not throw.');
        } catch (ValidationException $exception) {
            self::assertSame([
                ['code' => 'invalid_uuid', 'path' => 'teamId'],
                ['code' => 'unknown', 'path' => 'limit'],
            ], $exception->errors);
            self::assertSame('The request is invalid: teamId (invalid_uuid), limit (unknown).', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function unreadableResponses(): iterable
    {
        yield 'bad request without validation errors' => [400, '[]', 400];
        yield 'validation errors without a path' => [400, '[{"code":"invalid"}]', 400];
        yield 'validation errors with another status' => [422, '[{"code":"invalid","path":"teamId"}]', 422];
        yield 'team without a name' => [200, '{"id":"'.self::TEAM_ID.'"}', 200];
        yield 'not JSON' => [200, 'OK', 200];
    }

    #[DataProvider('unreadableResponses')]
    public function testReportsAResponseItCannotRead(int $statusCode, string $body, int $reportedStatusCode): void
    {
        $this->httpClient->willReturn($statusCode, $body);

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (UnexpectedResponseException $exception) {
            self::assertSame($reportedStatusCode, $exception->statusCode);
        }
    }

    #[DataProvider('serverFailures')]
    public function testReportsAServerFailureAsATransportFailure(int $statusCode, string $body): void
    {
        $this->httpClient->willReturn($statusCode, $body);

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('status '.$statusCode, $exception->getMessage());
            self::assertSame($statusCode, $exception->statusCode);
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function serverFailures(): iterable
    {
        yield 'a body that is not JSON' => [502, '<html>Bad Gateway</html>'];
        yield 'an error code' => [500, '{"code":"INTERNAL_ERROR","message":"The request failed."}'];
    }

    public function testReportsATransportFailure(): void
    {
        $thrown = new HttpClientException('connection reset');
        $this->httpClient->willThrow($thrown);

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('connection reset', $exception->getMessage());
            self::assertNull($exception->statusCode);
            // The cause is not chained: its trace holds the request with the access token.
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableAccessTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'trailing newline' => ["secret-access-token\n"];
        yield 'header injection' => ["secret-access-token\rX-Injected: 1"];
        yield 'space' => ['secret-access-token more'];
        yield 'non-ASCII byte' => ["secret-access-token\xFF"];
    }

    #[DataProvider('unusableAccessTokens')]
    public function testRefreshesInPlaceOfSendingAnAccessTokenOutsideVisibleAscii(string $accessToken): void
    {
        $apiClient = $this->apiClient(self::tokens($accessToken));
        $this->httpClient->willReturnJson(200, self::tokenResponse());
        $this->httpClient->willReturnJson(200, self::TEAM);

        $apiClient->teams->getTeam(self::TEAM_ID);

        self::assertCount(2, $this->httpClient->requests);
        self::assertSame('Bearer new-access-token', $this->httpClient->requests[1]->headers['Authorization']);
    }

    public function testRefreshesAgainOnTheNextCallAfterARefreshedAccessTokenItCannotSend(): void
    {
        $this->httpClient->willReturnJson(401, self::UNAUTHORIZED);
        $this->httpClient->willReturnJson(200, self::tokenResponse(['access_token' => "new-access-token\n"]));
        $this->httpClient->willReturnJson(200, self::tokenResponse(['refresh_token' => 'newer-refresh-token']));
        $this->httpClient->willReturnJson(200, self::TEAM);

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (UnexpectedResponseException) {
            self::assertCount(1, $this->stored);
        }
        $this->apiClient->teams->getTeam(self::TEAM_ID);

        parse_str($this->httpClient->requests[2]->body, $form);
        self::assertSame('new-refresh-token', $form['refresh_token']);
        self::assertSame('newer-refresh-token', $this->stored[1]->refreshToken);
    }

    public function testKeepsTheTokensWhenTheRefreshFailsInTransit(): void
    {
        $this->httpClient->willReturnJson(401, self::UNAUTHORIZED);
        $this->httpClient->willThrow(new HttpClientException('connection reset'));

        try {
            $this->apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (TransportException) {
            self::assertSame([], $this->stored);
            self::assertSame('the-refresh-token', $this->apiClient->tokens()->refreshToken);
        }
    }

    public function testHoldsTheNewTokensWhenTheStoreFunctionThrows(): void
    {
        $apiClient = new ApiClient(
            self::$config,
            self::tokens(secondsToExpiry: 0),
            static fn (Tokens $tokens) => throw new \RuntimeException('The store is down.'),
            $this->httpClient,
            FixedClock::at(self::NOW),
        );
        $this->httpClient->willReturnJson(200, self::tokenResponse());

        try {
            $apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call did not throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The store is down.', $exception->getMessage());
            self::assertSame('new-refresh-token', $apiClient->tokens()->refreshToken);
        }
    }

    public function testOmitsTheTokensFromDebugOutput(): void
    {
        $output = print_r($this->apiClient, true);

        self::assertStringNotContainsString('the-access-token', $output);
        self::assertStringNotContainsString('the-refresh-token', $output);
    }

    private function apiClient(#[\SensitiveParameter] Tokens $tokens): ApiClient
    {
        return new ApiClient(
            self::$config,
            $tokens,
            function (Tokens $tokens): void { $this->stored[] = $tokens; },
            $this->httpClient,
            FixedClock::at(self::NOW),
        );
    }

    private static function tokens(#[\SensitiveParameter] string $accessToken = 'the-access-token', int $secondsToExpiry = 3600): Tokens
    {
        return new Tokens(
            $accessToken,
            'the-refresh-token',
            new \DateTimeImmutable('@'.(self::NOW + $secondsToExpiry)),
            ['openid', 'offline_access', 'teams:read'],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function tokenResponse(array $overrides = []): array
    {
        return $overrides + [
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => 'openid offline_access teams:read',
        ];
    }
}
