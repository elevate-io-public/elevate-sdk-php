<?php

declare(strict_types=1);

namespace Elevate\SystemTests;

use Elevate\Auth\TokenClient;
use Elevate\Config;
use Elevate\Exception\TokenRequestException;
use Elevate\Http\CurlHttpClient;
use Elevate\Http\Request;
use Elevate\SystemTests\Support\FakeElevate;
use Elevate\Tests\Keys;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConnectTest extends SystemTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function privateKeys(): iterable
    {
        yield 'RSA' => [Keys::rsaPrivateKey()];
        yield 'P-256' => [Keys::ecPrivateKey()];
    }

    #[DataProvider('privateKeys')]
    public function testConnectsATeamAndReadsIt(string $privateKey): void
    {
        $this->start($privateKey);

        $connection = $this->connect();

        self::assertSame('team', $connection->resourceType);
        self::assertSame(self::TEAM_ID, $connection->resourceId);
        self::assertSame(['openid', 'offline_access', ...self::SCOPES], $connection->tokens->scopes);
        self::assertSame(self::NOW + self::LIFETIME, $connection->tokens->expiresAt->getTimestamp());

        $team = $this->apiClient($connection->tokens)->teams->getTeam($connection->resourceId);

        self::assertSame(self::TEAM_ID, $team->id);
        self::assertSame(FakeElevate::TEAM_NAME, $team->name);
        self::assertSame(['POST /oauth2/token', 'GET /v1/teams/'.self::TEAM_ID], array_map(static fn (array $request): string => $request['method'].' '.$request['uri'], $this->elevate->requests()));
        self::assertSame([], $this->stored);
    }

    public function testRefusesACodeWithAnotherVerifier(): void
    {
        $request = $this->tokenClient->authorizationRequest(self::REDIRECT_URI, self::SCOPES);
        $code = self::query($this->elevate->approve($request->url, self::TEAM_ID))['code'];

        try {
            $this->tokenClient->exchangeCode($code, self::REDIRECT_URI, 'another-verifier');
            self::fail('The exchange succeeded.');
        } catch (TokenRequestException $exception) {
            self::assertTrue($exception->isInvalidGrant());
        }
    }

    public function testRefusesACodeWithAnotherRedirectUri(): void
    {
        $request = $this->tokenClient->authorizationRequest(self::REDIRECT_URI, self::SCOPES);
        $code = self::query($this->elevate->approve($request->url, self::TEAM_ID))['code'];

        try {
            $this->tokenClient->exchangeCode($code, 'https://integrator.example.test/another', $request->codeVerifier);
            self::fail('The exchange succeeded.');
        } catch (TokenRequestException $exception) {
            self::assertTrue($exception->isInvalidGrant());
        }
    }

    public function testRefusesACodeTwice(): void
    {
        $request = $this->tokenClient->authorizationRequest(self::REDIRECT_URI, self::SCOPES);
        $code = self::query($this->elevate->approve($request->url, self::TEAM_ID))['code'];
        $this->tokenClient->exchangeCode($code, self::REDIRECT_URI, $request->codeVerifier);

        try {
            $this->tokenClient->exchangeCode($code, self::REDIRECT_URI, $request->codeVerifier);
            self::fail('The second exchange succeeded.');
        } catch (TokenRequestException $exception) {
            self::assertTrue($exception->isInvalidGrant());
        }
    }

    public function testRefusesAClientWithAnotherKey(): void
    {
        $request = $this->tokenClient->authorizationRequest(self::REDIRECT_URI, self::SCOPES);
        $code = self::query($this->elevate->approve($request->url, self::TEAM_ID))['code'];
        $other = new TokenClient(
            new Config($this->config->clientId, Keys::ecPrivateKey(), $this->config->keyId, $this->config->authUrl, $this->config->apiUrl),
            clock: $this->elevate,
        );

        try {
            $other->exchangeCode($code, self::REDIRECT_URI, $request->codeVerifier);
            self::fail('The exchange succeeded.');
        } catch (TokenRequestException $exception) {
            self::assertSame('invalid_client', $exception->error);
            self::assertSame(401, $exception->statusCode);
        }
    }

    public function testRefusesAClientAssertionTwice(): void
    {
        $this->connect();
        $recorded = $this->elevate->requests()[0];

        $response = (new CurlHttpClient())->send(new Request(
            $recorded['method'],
            $this->elevate->url.$recorded['uri'],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $recorded['body'],
        ));

        self::assertSame(401, $response->statusCode);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('invalid_client', $body['error'] ?? null);
    }
}
