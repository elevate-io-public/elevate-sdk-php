<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Model;

use Elevate\Api\Decode;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Paperback implements Cover
{
    public function __construct(
        public string $kind,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['kind'] ?? null, 'kind'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'kind' => $this->kind,
        ];
    }
}
