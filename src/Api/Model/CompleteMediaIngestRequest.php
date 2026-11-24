<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class CompleteMediaIngestRequest implements Model
{
    public function __construct(
        /**
         * @var list<CompletedPart>
         */
        public array $parts,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            array_map(static fn (mixed $item): CompletedPart => Decode::model(CompletedPart::class, $item, 'parts[]'), Decode::list($data['parts'] ?? null, 'parts')),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'parts' => array_values($this->parts), // @phpstan-ignore arrayValues.list
        ];
    }
}
