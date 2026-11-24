<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class CreateMediaIngestPartUrlsRequest implements Model
{
    public function __construct(
        /**
         * The first part number of the range, inclusive. The first part is 1.
         */
        public int $chunkFrom,
        /**
         * The last part number of the range, inclusive.
         */
        public int $chunkTo,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::int($data['chunkFrom'] ?? null, 'chunkFrom'),
            Decode::int($data['chunkTo'] ?? null, 'chunkTo'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'chunkFrom' => $this->chunkFrom,
            'chunkTo' => $this->chunkTo,
        ];
    }
}
