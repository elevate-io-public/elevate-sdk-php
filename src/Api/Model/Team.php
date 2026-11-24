<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Team implements Model
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['id'] ?? null, 'id'),
            Decode::string($data['name'] ?? null, 'name'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
