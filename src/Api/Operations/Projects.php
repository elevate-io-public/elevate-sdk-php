<?php

declare(strict_types=1);

namespace Elevate\Api\Operations;

use Elevate\Api\Model\CreateProjectRequest;
use Elevate\Api\Model\ListProjectsResponse;
use Elevate\Api\Model\ProjectRole;
use Elevate\Api\Model\ProjectWithPermissions;
use Elevate\Api\Transport;
use Elevate\Exception\ApiException;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;

/**
 * The projects in the connected team.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Projects
{
    public function __construct(private Transport $transport)
    {
    }

    /**
     * List the projects in the team that the user can access, with the user's permissions on each.
     * With the role parameter, the list holds only the projects where the user has at least that role.
     * It needs the `projects:read` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function listProjects(string $teamId, ?ProjectRole $role = null, ?int $limit = null, ?string $after = null): ListProjectsResponse
    {
        return $this->transport->call('GET', '/v1/teams/{teamId}/projects', ['teamId' => $teamId], ['role' => $role?->value, 'limit' => $limit, 'after' => $after], null, ListProjectsResponse::class);
    }

    /**
     * Create a project in the team. The user becomes its editor.
     * It needs the `projects:write` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function createProject(string $teamId, CreateProjectRequest $body): ProjectWithPermissions
    {
        return $this->transport->call('POST', '/v1/teams/{teamId}/projects', ['teamId' => $teamId], [], $body, ProjectWithPermissions::class);
    }
}
