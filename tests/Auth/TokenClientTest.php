<?php

declare(strict_types=1);

namespace Elevate\Tests\Auth;

use Elevate\Auth\TokenClient;
use Elevate\Config;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Http\HttpClientException;
use Elevate\Tests\FixedClock;
use Elevate\Tests\Keys;
use Elevate\Tests\RecordingHttpClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenClientTest extends TestCase
{
    private const int NOW = 1_800_000_000;
    private const string AUTH_URL = 'https://auth.example.test';
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
            new Config(self::CLIENT_ID, self::$privateKey, 'key-id', self::AUTH_URL),
            $this->httpClient,
            clock: FixedClock::at(self::NOW),
        );
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
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
        $this->httpClient->willReturn(502, '<html>Bad Gateway</html>');

        try {
            $this->tokenClient->exchangeCode('the-code', 'https://integrator.example.test/callback', 'the-verifier');
            self::fail('The exchange did not throw.');
        } catch (UnexpectedResponseException $exception) {
            self::assertStringContainsString('status 502', $exception->getMessage());
            self::assertSame(502, $exception->statusCode);
        }
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

    private static function assertTraceOmits(\Throwable $exception, string ...$secrets): void
    {
        $strings = [];
        $arguments = array_column($exception->getTrace(), 'args');
        array_walk_recursive($arguments, static function (mixed $argument) use (&$strings): void {
            if (\is_string($argument)) {
                $strings[] = $argument;
            }
        });
        self::assertNotEmpty($arguments, 'The trace carries no arguments.');
        foreach ($secrets as $secret) {
            foreach ($strings as $string) {
                self::assertStringNotContainsString($secret, $string);
            }
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
