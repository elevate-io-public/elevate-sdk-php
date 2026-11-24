<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class ColourTagValue implements TagValue
{
    public function __construct(
        public string $type,
        public string $value,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['type'] ?? null, 'type'),
            Decode::string($data['value'] ?? null, 'value'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'type' => $this->type,
            'value' => $this->value,
        ];
    }
}
