<?php

declare(strict_types=1);

namespace Elevate\Api\Operations;

use Elevate\Api\Model\Team;
use Elevate\Api\Transport;
use Elevate\Exception\ApiException;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;

/**
 * The connected team.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Teams
{
    public function __construct(private Transport $transport)
    {
    }

    /**
     * Get the details of the team.
     * It needs the `teams:read` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function getTeam(string $teamId): Team
    {
        return $this->transport->call('GET', '/v1/teams/{teamId}', ['teamId' => $teamId], [], null, Team::class);
    }
}
