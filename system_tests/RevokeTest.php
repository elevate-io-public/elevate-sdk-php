<?php

declare(strict_types=1);

namespace Elevate\SystemTests;

final class RevokeTest extends SystemTestCase
{
    public function testReportsAConnectionThatTheIntegratorRevoked(): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);

        $apiClient->auth->revokeGrant();

        $this->assertConnectionIsRevoked($apiClient);
    }

    public function testReportsAConnectionThatTheUserRevoked(): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);

        $this->elevate->revokeConnection(self::TEAM_ID);

        $this->assertConnectionIsRevoked($apiClient);
    }
}
