<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * The user's permissions on a project.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class ProjectPermissions implements Model
{
    public function __construct(
        /**
         * A value of ProjectRole, or one that a later version of the API added.
         */
        public string $role,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['role'] ?? null, 'role'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'role' => $this->role,
        ];
    }
}
