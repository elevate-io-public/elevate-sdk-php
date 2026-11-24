<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Author implements Model
{
    public function __construct(
        public string $name,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['name'] ?? null, 'name'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'name' => $this->name,
        ];
    }
}
