<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Model;

use Elevate\Api\Decode;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Hardcover implements Cover
{
    public function __construct(
        public string $kind,
        public bool $jacket,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['kind'] ?? null, 'kind'),
            Decode::bool($data['jacket'] ?? null, 'jacket'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'kind' => $this->kind,
            'jacket' => $this->jacket,
        ];
    }
}
