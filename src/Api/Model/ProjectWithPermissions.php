<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class ProjectWithPermissions implements Model
{
    public function __construct(
        public Project $project,
        public ProjectPermissions $permissions,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::model(Project::class, $data['project'] ?? null, 'project'),
            Decode::model(ProjectPermissions::class, $data['permissions'] ?? null, 'permissions'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'project' => $this->project,
            'permissions' => $this->permissions,
        ];
    }
}
