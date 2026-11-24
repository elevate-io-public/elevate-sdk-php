<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class InitMediaIngestResponse implements Model
{
    public function __construct(
        /**
         * The media that the ingest creates.
         */
        public string $mediaId,
        /**
         * The size of every part except the last. Split the file on these boundaries.
         * A part of a different size corrupts the assembled file.
         */
        public int $maxChunkSizeBytes,
        /**
         * The number of parts in the upload.
         */
        public int $chunkCount,
        /**
         * A presigned URL for every part, in part number order.
         *
         * @var list<PartUrl>
         */
        public array $partUrls,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['mediaId'] ?? null, 'mediaId'),
            Decode::int($data['maxChunkSizeBytes'] ?? null, 'maxChunkSizeBytes'),
            Decode::int($data['chunkCount'] ?? null, 'chunkCount'),
            array_map(static fn (mixed $item): PartUrl => Decode::model(PartUrl::class, $item, 'partUrls[]'), Decode::list($data['partUrls'] ?? null, 'partUrls')),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'mediaId' => $this->mediaId,
            'maxChunkSizeBytes' => $this->maxChunkSizeBytes,
            'chunkCount' => $this->chunkCount,
            'partUrls' => array_values($this->partUrls), // @phpstan-ignore arrayValues.list
        ];
    }
}
