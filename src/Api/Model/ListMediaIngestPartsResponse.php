<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class ListMediaIngestPartsResponse implements Model
{
    public function __construct(
        /**
         * The size of every part except the last. Split the file on these boundaries.
         */
        public int $maxChunkSizeBytes,
        /**
         * The number of parts in the upload.
         */
        public int $chunkCount,
        /**
         * @var list<UploadedPart>
         */
        public array $parts,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::int($data['maxChunkSizeBytes'] ?? null, 'maxChunkSizeBytes'),
            Decode::int($data['chunkCount'] ?? null, 'chunkCount'),
            array_map(static fn (mixed $item): UploadedPart => Decode::model(UploadedPart::class, $item, 'parts[]'), Decode::list($data['parts'] ?? null, 'parts')),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'maxChunkSizeBytes' => $this->maxChunkSizeBytes,
            'chunkCount' => $this->chunkCount,
            'parts' => array_values($this->parts), // @phpstan-ignore arrayValues.list
        ];
    }
}
