<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class InitMediaIngestRequest implements Model
{
    public function __construct(
        /**
         * The name of the file. It becomes the media name.
         */
        public string $filename,
        /**
         * The size of the file. elevate.io checks the storage quota against it.
         */
        public int $sizeBytes,
        /**
         * The project to add the media to. The user must have edit permission on the project.
         * Omit it to add the media to the team without a project.
         */
        public ?string $projectId = null,
        /**
         * The tags to store on the media, keyed by tag key ID. elevate.io supplies the tag key IDs.
         * Each value's type must match its key's value type.
         *
         * @var array<string, TagValue>|null
         */
        public ?array $tags = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['filename'] ?? null, 'filename'),
            Decode::int($data['sizeBytes'] ?? null, 'sizeBytes'),
            isset($data['projectId']) ? Decode::string($data['projectId'], 'projectId') : null,
            isset($data['tags']) ? array_map(static fn (mixed $item): TagValue => Decode::union(['freetext' => FreetextTagValue::class, 'keyword' => KeywordTagValue::class, 'datetime' => DatetimeTagValue::class, 'boolean' => BooleanTagValue::class, 'colour' => ColourTagValue::class, 'coordinates' => CoordinatesTagValue::class], 'type', $item, 'tags[]'), Decode::map($data['tags'], 'tags')) : null,
        );
    }

    public function jsonSerialize(): object
    {
        return (object) array_filter([
            'filename' => $this->filename,
            'sizeBytes' => $this->sizeBytes,
            'projectId' => $this->projectId,
            'tags' => null === $this->tags ? null : (object) $this->tags,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
