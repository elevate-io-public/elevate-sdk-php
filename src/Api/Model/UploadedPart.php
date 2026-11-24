<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class UploadedPart implements Model
{
    public function __construct(
        public int $partNumber,
        /**
         * The ETag that elevate.io holds for the part. Pass it back when completing the ingest.
         */
        public string $eTag,
        public int $sizeBytes,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::int($data['partNumber'] ?? null, 'partNumber'),
            Decode::string($data['eTag'] ?? null, 'eTag'),
            Decode::int($data['sizeBytes'] ?? null, 'sizeBytes'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'partNumber' => $this->partNumber,
            'eTag' => $this->eTag,
            'sizeBytes' => $this->sizeBytes,
        ];
    }
}
