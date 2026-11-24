<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class KeywordTagValue implements TagValue
{
    public function __construct(
        public string $type,
        /**
         * @var list<string>
         */
        public array $values,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['type'] ?? null, 'type'),
            array_map(static fn (mixed $item): string => Decode::string($item, 'values[]'), Decode::list($data['values'] ?? null, 'values')),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'type' => $this->type,
            'values' => array_values($this->values), // @phpstan-ignore arrayValues.list
        ];
    }
}
