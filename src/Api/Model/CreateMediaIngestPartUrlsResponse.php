<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class CreateMediaIngestPartUrlsResponse implements Model
{
    public function __construct(
        /**
         * @var list<PartUrl>
         */
        public array $partUrls,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            array_map(static fn (mixed $item): PartUrl => Decode::model(PartUrl::class, $item, 'partUrls[]'), Decode::list($data['partUrls'] ?? null, 'partUrls')),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'partUrls' => array_values($this->partUrls), // @phpstan-ignore arrayValues.list
        ];
    }
}
