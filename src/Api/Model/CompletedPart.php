<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class CompletedPart implements Model
{
    public function __construct(
        public int $partNumber,
        /**
         * The ETag header from the response to the part's upload request.
         */
        public string $eTag,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::int($data['partNumber'] ?? null, 'partNumber'),
            Decode::string($data['eTag'] ?? null, 'eTag'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'partNumber' => $this->partNumber,
            'eTag' => $this->eTag,
        ];
    }
}
