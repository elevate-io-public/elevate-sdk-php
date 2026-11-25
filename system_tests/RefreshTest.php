<?php

declare(strict_types=1);

namespace Elevate\SystemTests;

use Elevate\Api\ApiClient;
use Elevate\Auth\Tokens;
use Elevate\Exception\TransportException;
use Elevate\SystemTests\Support\FakeElevate;
use Elevate\Tests\FixedClock;

final class RefreshTest extends SystemTestCase
{
    public function testRefreshesBeforeTheExpiryAndStoresTheTokens(): void
    {
        $tokens = $this->connect()->tokens;
        $apiClient = $this->apiClient($tokens);

        $this->elevate->advance(self::LIFETIME - 31);
        $apiClient->teams->getTeam(self::TEAM_ID);
        self::assertCount(0, $this->stored);

        $this->elevate->advance(1);
        $apiClient->teams->getTeam(self::TEAM_ID);
        $apiClient->teams->getTeam(self::TEAM_ID);

        self::assertCount(1, $this->stored);
        self::assertNotSame($tokens->accessToken, $this->stored[0]->accessToken);
        self::assertNotSame($tokens->refreshToken, $this->stored[0]->refreshToken);
        self::assertSame($this->stored[0], $apiClient->tokens());
        self::assertSame(self::NOW + 2 * self::LIFETIME - 30, $this->stored[0]->expiresAt->getTimestamp());
    }

    public function testKeepsAConnectionThroughSeveralRefreshes(): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);

        for ($round = 0; $round < 3; ++$round) {
            $this->elevate->advance(self::LIFETIME);
            self::assertSame(FakeElevate::TEAM_NAME, $apiClient->teams->getTeam(self::TEAM_ID)->name);
        }

        self::assertCount(3, $this->stored);
        self::assertCount(3, array_unique(array_map(static fn (Tokens $tokens): string => $tokens->refreshToken, $this->stored)));
    }

    public function testResumesFromTheStoredTokens(): void
    {
        $tokens = $this->connect()->tokens;
        $this->elevate->advance(self::LIFETIME);
        $this->apiClient($tokens)->teams->getTeam(self::TEAM_ID);

        $this->elevate->advance(self::LIFETIME);
        $team = $this->apiClient($this->stored[0])->teams->getTeam(self::TEAM_ID);

        self::assertSame(FakeElevate::TEAM_NAME, $team->name);
        self::assertCount(2, $this->stored);
    }

    public function testRefreshesWhenElevateEndsTheAccessToken(): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);
        $this->elevate->endAccessTokens();

        $team = $apiClient->teams->getTeam(self::TEAM_ID);

        self::assertSame(FakeElevate::TEAM_NAME, $team->name);
        self::assertCount(1, $this->stored);
        self::assertSame(['getTeam', 'getTeam'], $this->elevate->operations());
    }

    public function testRefreshesWhenTheAccessTokenExpiredAndTheClockOfTheClientLags(): void
    {
        $tokens = $this->connect()->tokens;
        $this->elevate->advance(self::LIFETIME);
        // Lags by 31 seconds: the client then takes the access token as valid, and its client assertion, which lives 60
        // seconds, has not expired at the refresh.
        $apiClient = $this->apiClient($tokens, FixedClock::at(self::NOW + self::LIFETIME - 31));

        $team = $apiClient->teams->getTeam(self::TEAM_ID);

        self::assertSame(FakeElevate::TEAM_NAME, $team->name);
        self::assertCount(1, $this->stored);
        self::assertSame(['getTeam', 'getTeam'], $this->elevate->operations());
    }

    public function testRefreshesAgainWithin60SecondsOfALostRefreshResponse(): void
    {
        $apiClient = $this->apiClientAfterALostRefreshResponse();

        $this->elevate->advance(FakeElevate::REFRESH_GRACE_SECONDS);

        self::assertSame(FakeElevate::TEAM_NAME, $apiClient->teams->getTeam(self::TEAM_ID)->name);
        self::assertCount(1, $this->stored);
        self::assertSame($this->stored[0], $apiClient->tokens());
    }

    public function testCannotRefreshAgain61SecondsAfterALostRefreshResponse(): void
    {
        $apiClient = $this->apiClientAfterALostRefreshResponse();

        $this->elevate->advance(FakeElevate::REFRESH_GRACE_SECONDS + 1);

        $this->assertConnectionIsRevoked($apiClient);
    }

    /**
     * Returns a client whose refresh reached elevate.io, which replaced the refresh token, while the response was lost.
     */
    private function apiClientAfterALostRefreshResponse(): ApiClient
    {
        $tokens = $this->connect()->tokens;
        $apiClient = $this->apiClient($tokens);
        $this->elevate->advance(self::LIFETIME);
        $this->elevate->loseNextResponse();

        try {
            $apiClient->teams->getTeam(self::TEAM_ID);
            self::fail('The call succeeded.');
        } catch (TransportException) {
            self::assertSame($tokens, $apiClient->tokens());
            self::assertSame([], $this->stored);
        }

        return $apiClient;
    }
}
