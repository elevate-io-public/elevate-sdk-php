<?php

declare(strict_types=1);

namespace Elevate\Tests\Auth;

use Elevate\Auth\TokenClient;
use Elevate\Config;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Http\HttpClientException;
use Elevate\Tests\AssertsTraces;
use Elevate\Tests\FixedClock;
use Elevate\Tests\Keys;
use Elevate\Tests\RecordingHttpClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenClientTest extends TestCase
{
    use AssertsTraces;

    private const int NOW = 1_800_000_000;
    private const string AUTH_URL = 'https://auth.example.test';
    private const string API_URL = 'https://api.example.test';
    private const string CLIENT_ID = 'client-id';

    private static string $privateKey;
    private RecordingHttpClient $httpClient;
    private TokenClient $tokenClient;

    public static function setUpBeforeClass(): void
    {
        self::$privateKey = Keys::ecPrivateKey();
    }

    protected function setUp(): void
    {
        JWT::$timestamp = self::NOW;
        $this->httpClient = new RecordingHttpClient();
        $this->tokenClient = new TokenClient(
            new Config(self::CLIENT_ID, self::$privateKey, 'key-id', self::AUTH_URL, self::API_URL),
            $this->httpClient,
            clock: FixedClock::at(self::NOW),
        );
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
    }

    public function testBuildsAnAuthorizationRequestWithPkceAndState(): void
    {
        $request = $this->tokenClient->authorizationRequest('https://integrator.example.test/callback', ['select:team', 'teams:read']);

        self::assertStringStartsWith(self::AUTH_URL.'/oauth2/auth?', $request->url);
        parse_str((string) parse_url($request->url, \PHP_URL_QUERY), $query);
        self::assertSame([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => 'https://integrator.example.test/callback',
            'scope' => 'openid offline_access select:team teams:read',
            'audience' => self::API_URL,
            'state' => $request->state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $request->codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $query);
        self::assertStringContainsString('scope=openid%20offline_access%20select%3Ateam%20teams%3Aread', $request->url);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $request->state);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $request->codeVerifier);
    }

    public function testDropsARepeatedScopeAndAnEmptyScope(): void
    {
        $request = $this->tokenClient->authorizationRequest('https://integrator.example.test/callback', ['openid', '', 'teams:read']);

        parse_str((string) parse_url($request->url, \PHP_URL_QUERY), $query);
        self::assertSame('openid offline_access teams:read', $query['scope']);
    }

    public function testEachAuthorizationRequestHasItsOwnStateAndVerifier(): void
    {
        $first = $this->tokenClient->authorizationRequest('https://integrator.example.test/callback', []);
        $second = $this->tokenClient->authorizationRequest('https://integrator.example.test/callback', []);

        self::assertNotSame($first->state, $second->state);
        self::assertNotSame($first->codeVerifier, $second->codeVerifier);
    }

    public function testExchangesACodeForTokensAndTheConnectedResource(): void
    {
        $this->httpClient->willReturnJson(200, self::tokenResponse(['id_token' => self::idToken()]));

        $connection = $this->tokenClient->exchangeCode('the-code', 'https://integrator.example.test/callback', 'the-verifier');

        self::assertSame('team', $connection->resourceType);
        self::assertSame('team-id', $connection->resourceId);
        self::assertSame('access-token', $connection->tokens->accessToken);
        self::assertSame('refresh-token', $connection->tokens->refreshToken);
        self::assertSame(self::NOW + 3600, $connection->tokens->expiresAt->getTimestamp());
        self::assertSame(['openid', 'offline_access', 'teams:read'], $connection->tokens->scopes);

        $request = $this->httpClient->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame(self::AUTH_URL.'/oauth2/token', $request->url);
        self::assertSame('application/x-www-form-urlencoded', $request->headers['Content-Type']);
        $form = $this->httpClient->lastForm();
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('the-code', $form['code']);
        self::assertSame('https://integrator.example.test/callback', $form['redirect_uri']);
        self::assertSame('the-verifier', $form['code_verifier']);
        $this->assertCarriesAClientAssertion($form);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unreadableExchangeResponses(): iterable
    {
        yield 'no ID token' => [self::tokenResponse(), '"openid" scope'];
        yield 'ID token is not a JWT' => [self::tokenResponse(['id_token' => 'not-a-jwt']), 'not a signed JWT'];
        yield 'another issuer' => [self::tokenResponse(['id_token' => self::idToken(['iss' => 'https://other.example.test'])]), 'another issuer'];
        yield 'another audience' => [self::tokenResponse(['id_token' => self::idToken(['aud' => ['other-client']])]), 'another audience'];
        yield 'expired ID token' => [self::tokenResponse(['id_token' => self::idToken(['exp' => self::NOW])]), 'expired'];
        yield 'no resource' => [self::tokenResponse(['id_token' => self::idToken(['resource_id' => null])]), 'connected resource'];
        yield 'no refresh token' => [self::tokenResponse(['id_token' => self::idToken(), 'refresh_token' => null]), '"offline_access" scope'];
        yield 'no access token' => [self::tokenResponse(['id_token' => self::idToken(), 'access_token' => null]), 'access token'];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('unreadableExchangeResponses')]
    public function testRejectsAnExchangeResponseItCannotRead(array $response, string $message): void
    {
        $this->httpClient->willReturnJson(200, $response);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage($message);

        $this->tokenClient->exchangeCode('the-code', 'https://integrator.example.test/callback', 'the-verifier');
    }

    public function testReportsAnOAuth2Error(): void
    {
        $this->httpClient->willReturnJson(400, ['error' => 'invalid_grant', 'error_description' => 'The code has expired.']);

        try {
            $this->tokenClient->exchangeCode('the-code', 'https://integrator.example.test/callback', 'the-verifier');
            self::fail('The exchange did not throw.');
        } catch (TokenRequestException $exception) {
            self::assertSame('invalid_grant', $exception->error);
            self::assertSame('The code has expired.', $exception->errorDescription);
            self::assertSame(400, $exception->statusCode);
        }
    }

    public function testReportsAResponseThatIsNotATokenResponse(): void
    {
        $this->httpClient->willReturn(404, '<html>Not Found</html>');

        try {
            $this->tokenClient->exchangeCode('the-code', 'https://integrator.example.test/callback', 'the-verifier');
            self::fail('The exchange did not throw.');
        } catch (UnexpectedResponseException $exception) {
            self::assertStringContainsString('status 404', $exception->getMessage());
            self::assertSame(404, $exception->statusCode);
        }
    }

    #[DataProvider('serverFailures')]
    public function testReportsAServerFailureAsATransportFailure(int $statusCode, string $body): void
    {
        $this->httpClient->willReturn($statusCode, $body);

        try {
            $this->tokenClient->exchangeCode('the-code', 'https://integrator.example.test/callback', 'the-verifier');
            self::fail('The exchange did not throw.');
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
        yield 'an OAuth2 error' => [500, '{"error":"server_error"}'];
    }

    public function testReportsTheStatusOfASuccessfulResponseItCannotRead(): void
    {
        $this->httpClient->willReturn(200, 'not JSON');

        try {
            $this->tokenClient->exchangeCode('the-code', 'https://integrator.example.test/callback', 'the-verifier');
            self::fail('The exchange did not throw.');
        } catch (UnexpectedResponseException $exception) {
            self::assertSame(200, $exception->statusCode);
        }
    }

    public function testReportsATransportFailure(): void
    {
        $clientException = new HttpClientException('connection reset');
        $this->httpClient->willThrow($clientException);

        try {
            $this->tokenClient->exchangeCode('the-code', 'https://integrator.example.test/callback', 'the-verifier');
            self::fail('The exchange did not throw.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('connection reset', $exception->getMessage());
            self::assertNull($exception->statusCode);
            // The cause is not chained: its trace holds the request with the code.
            self::assertNull($exception->getPrevious());
        }
    }

    public function testKeepsTheCodeAndTheVerifierOutOfTheTraceOfAFailedRequest(): void
    {
        $this->httpClient->willThrow(new HttpClientException('connection reset'));

        try {
            $this->tokenClient->exchangeCode('secret-code', 'https://integrator.example.test/callback', 'secret-verifier');
            self::fail('The exchange did not throw.');
        } catch (TransportException $exception) {
            self::assertTraceOmits($exception, 'secret-code', 'secret-verifier');
        }
    }

    public function testKeepsTheTokensOutOfTheTraceOfAnExchangeResponseItCannotRead(): void
    {
        $idToken = self::idToken();
        $this->httpClient->willReturnJson(200, self::tokenResponse([
            'id_token' => $idToken,
            'refresh_token' => 'secret-refresh-token',
            'access_token' => null,
        ]));

        try {
            $this->tokenClient->exchangeCode('secret-code', 'https://integrator.example.test/callback', 'secret-verifier');
            self::fail('The exchange did not throw.');
        } catch (UnexpectedResponseException $exception) {
            self::assertTraceOmits($exception, 'secret-code', 'secret-verifier', 'secret-refresh-token', $idToken);
        }
    }

    public function testRefreshesTheTokens(): void
    {
        $this->httpClient->willReturnJson(200, self::tokenResponse(['access_token' => 'next-access-token', 'refresh_token' => 'next-refresh-token']));

        $tokens = $this->tokenClient->refresh('refresh-token');

        self::assertSame('next-access-token', $tokens->accessToken);
        self::assertSame('next-refresh-token', $tokens->refreshToken);
        self::assertSame(self::NOW + 3600, $tokens->expiresAt->getTimestamp());
        self::assertSame(['openid', 'offline_access', 'teams:read'], $tokens->scopes);

        self::assertSame(self::AUTH_URL.'/oauth2/token', $this->httpClient->requests[0]->url);
        $form = $this->httpClient->lastForm();
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('refresh-token', $form['refresh_token']);
        $this->assertCarriesAClientAssertion($form);
    }

    public function testReportsARevokedConnectionOnRefresh(): void
    {
        $this->httpClient->willReturnJson(400, ['error' => 'invalid_grant']);

        try {
            $this->tokenClient->refresh('refresh-token');
            self::fail('The refresh did not throw.');
        } catch (TokenRequestException $exception) {
            self::assertTrue($exception->isInvalidGrant());
            self::assertNull($exception->errorDescription);
        }
    }

    public function testDoesNotReportAnotherOAuth2ErrorAsARevokedConnection(): void
    {
        $this->httpClient->willReturnJson(401, ['error' => 'invalid_client']);

        try {
            $this->tokenClient->refresh('refresh-token');
            self::fail('The refresh did not throw.');
        } catch (TokenRequestException $exception) {
            self::assertFalse($exception->isInvalidGrant());
        }
    }

    public function testRejectsARefreshResponseWithoutARefreshToken(): void
    {
        $this->httpClient->willReturnJson(200, self::tokenResponse(['refresh_token' => null]));

        try {
            $this->tokenClient->refresh('refresh-token');
            self::fail('The refresh did not throw.');
        } catch (UnexpectedResponseException $exception) {
            self::assertSame('The token response has no refresh token.', $exception->getMessage());
        }
    }

    public function testKeepsTheRefreshTokenOutOfTheTraceOfAFailedRequest(): void
    {
        $this->httpClient->willThrow(new HttpClientException('connection reset'));

        try {
            $this->tokenClient->refresh('secret-refresh-token');
            self::fail('The refresh did not throw.');
        } catch (TransportException $exception) {
            self::assertTraceOmits($exception, 'secret-refresh-token');
        }
    }

    public function testKeepsTheTokensOutOfTheTraceOfARefreshResponseItCannotRead(): void
    {
        $this->httpClient->willReturnJson(200, self::tokenResponse(['access_token' => 'secret-access-token', 'refresh_token' => null]));

        try {
            $this->tokenClient->refresh('secret-refresh-token');
            self::fail('The refresh did not throw.');
        } catch (UnexpectedResponseException $exception) {
            self::assertTraceOmits($exception, 'secret-refresh-token', 'secret-access-token');
        }
    }

    /**
     * @param array<array-key, mixed> $form
     */
    private function assertCarriesAClientAssertion(array $form): void
    {
        self::assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type']);
        self::assertIsString($form['client_assertion']);
        $claims = (array) JWT::decode($form['client_assertion'], new Key(Keys::publicKey(self::$privateKey), 'ES256'));
        self::assertSame(self::CLIENT_ID, $claims['sub']);
        self::assertSame(self::AUTH_URL.'/oauth2/token', $claims['aud']);
    }

    /**
     * @param array<string, mixed> $overrides a null value removes the field
     *
     * @return array<string, mixed>
     */
    private static function tokenResponse(array $overrides = []): array
    {
        return array_filter($overrides + [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => 'openid offline_access teams:read',
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param array<string, mixed> $overrides a null value removes the claim
     */
    private static function idToken(array $overrides = []): string
    {
        $claims = array_filter($overrides + [
            'iss' => self::AUTH_URL,
            'sub' => 'user-id',
            'aud' => [self::CLIENT_ID],
            'resource_type' => 'team',
            'resource_id' => 'team-id',
            'iat' => self::NOW,
            'exp' => self::NOW + 3600,
        ], static fn (mixed $value): bool => null !== $value);

        return JWT::encode($claims, Keys::ecPrivateKey(), 'ES256');
    }
}
