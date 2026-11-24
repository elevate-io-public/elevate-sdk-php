<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class BookList implements Model
{
    public function __construct(
        /**
         * @var list<Book>
         */
        public array $items,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            array_map(static fn (mixed $item): Book => Decode::model(Book::class, $item, 'items[]'), Decode::list($data['items'] ?? null, 'items')),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'items' => array_values($this->items), // @phpstan-ignore arrayValues.list
        ];
    }
}
