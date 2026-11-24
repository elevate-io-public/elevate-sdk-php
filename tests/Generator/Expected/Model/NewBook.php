<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class NewBook implements Model
{
    public function __construct(
        public string $title,
        public Genre $genre,
        public ?Author $author = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['title'] ?? null, 'title'),
            Decode::enum(Genre::class, $data['genre'] ?? null, 'genre'),
            isset($data['author']) ? Decode::model(Author::class, $data['author'], 'author') : null,
        );
    }

    public function jsonSerialize(): object
    {
        return (object) array_filter([
            'title' => $this->title,
            'genre' => $this->genre,
            'author' => $this->author,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
