<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * A book on a shelf.
 *
 * It has one author * / and no more.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Book implements Model
{
    public function __construct(
        public string $id,
        public int $pages,
        public bool $signed,
        public Author $author,
        /**
         * A value of Genre, or one that a later version of the API added.
         */
        public string $genre,
        /**
         * The tags of the book.
         *
         * @var list<string>
         */
        public array $tags,
        /**
         * The line under the title.
         * Absent when the book has none.
         */
        public ?string $subtitle = null,
        public ?float $price = null,
        /**
         * A value of Condition, or one that a later version of the API added.
         */
        public ?string $condition = null,
        public ?Cover $cover = null,
        /**
         * The translated titles by language, each with its editions.
         *
         * @var array<string, list<string>>|null
         */
        public ?array $translations = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['id'] ?? null, 'id'),
            Decode::int($data['pages'] ?? null, 'pages'),
            Decode::bool($data['signed'] ?? null, 'signed'),
            Decode::model(Author::class, $data['author'] ?? null, 'author'),
            Decode::string($data['genre'] ?? null, 'genre'),
            array_map(static fn (mixed $item): string => Decode::string($item, 'tags[]'), Decode::list($data['tags'] ?? null, 'tags')),
            isset($data['subtitle']) ? Decode::string($data['subtitle'], 'subtitle') : null,
            isset($data['price']) ? Decode::float($data['price'], 'price') : null,
            isset($data['condition']) ? Decode::string($data['condition'], 'condition') : null,
            isset($data['cover']) ? Decode::union(['hardcover' => Hardcover::class, 'paperback' => Paperback::class], 'kind', $data['cover'], 'cover') : null,
            isset($data['translations']) ? array_map(static fn (mixed $item): array => array_map(static fn (mixed $item2): string => Decode::string($item2, 'translations[][]'), Decode::list($item, 'translations[]')), Decode::map($data['translations'], 'translations')) : null,
        );
    }

    public function jsonSerialize(): object
    {
        return (object) array_filter([
            'id' => $this->id,
            'pages' => $this->pages,
            'signed' => $this->signed,
            'author' => $this->author,
            'genre' => $this->genre,
            'tags' => array_values($this->tags), // @phpstan-ignore arrayValues.list
            'subtitle' => $this->subtitle,
            'price' => $this->price,
            'condition' => $this->condition,
            'cover' => $this->cover,
            'translations' => null === $this->translations ? null : (object) $this->translations,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
