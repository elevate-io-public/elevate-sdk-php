<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class ListProjectsResponse implements Model
{
    public function __construct(
        /**
         * @var list<ProjectWithPermissions>
         */
        public array $items,
        /**
         * Whether there are more projects to fetch after the last item in the list.
         */
        public bool $hasMoreAfter,
        /**
         * A cursor to fetch the next page of projects. Pass it as the after parameter of the next request.
         * Present when hasMoreAfter is true.
         */
        public ?string $nextCursor = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            array_map(static fn (mixed $item): ProjectWithPermissions => Decode::model(ProjectWithPermissions::class, $item, 'items[]'), Decode::list($data['items'] ?? null, 'items')),
            Decode::bool($data['hasMoreAfter'] ?? null, 'hasMoreAfter'),
            isset($data['nextCursor']) ? Decode::string($data['nextCursor'], 'nextCursor') : null,
        );
    }

    public function jsonSerialize(): object
    {
        return (object) array_filter([
            'items' => array_values($this->items), // @phpstan-ignore arrayValues.list
            'hasMoreAfter' => $this->hasMoreAfter,
            'nextCursor' => $this->nextCursor,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
