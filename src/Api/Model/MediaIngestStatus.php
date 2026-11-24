<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class MediaIngestStatus implements Model
{
    public function __construct(
        public string $mediaId,
        /**
         * A value of IngestStatus, or one that a later version of the API added.
         */
        public string $status,
        /**
         * The ingest progress. Present while the status is INGESTING.
         */
        public ?int $progressPercentage = null,
        public ?IngestFailure $failure = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['mediaId'] ?? null, 'mediaId'),
            Decode::string($data['status'] ?? null, 'status'),
            isset($data['progressPercentage']) ? Decode::int($data['progressPercentage'], 'progressPercentage') : null,
            isset($data['failure']) ? Decode::model(IngestFailure::class, $data['failure'], 'failure') : null,
        );
    }

    public function jsonSerialize(): object
    {
        return (object) array_filter([
            'mediaId' => $this->mediaId,
            'status' => $this->status,
            'progressPercentage' => $this->progressPercentage,
            'failure' => $this->failure,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
