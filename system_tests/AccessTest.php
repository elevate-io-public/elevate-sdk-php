<?php

declare(strict_types=1);

namespace Elevate\SystemTests;

use Elevate\Exception\ApiException;

final class AccessTest extends SystemTestCase
{
    public function testRefusesATeamOutsideTheConnection(): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);

        try {
            $apiClient->teams->getTeam(self::OTHER_TEAM_ID);
            self::fail('The call succeeded.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->isNotGranted());
            self::assertSame(403, $exception->statusCode);
            self::assertSame([], $this->stored);
        }
    }

    public function testRefusesAnOperationOutsideTheScopes(): void
    {
        $apiClient = $this->apiClient($this->connect(['select:team', 'teams:read'])->tokens);

        try {
            $apiClient->projects->listProjects(self::TEAM_ID);
            self::fail('The call succeeded.');
        } catch (ApiException $exception) {
            self::assertSame('INSUFFICIENT_SCOPE', $exception->errorCode);
            self::assertSame(403, $exception->statusCode);
        }
    }
}
