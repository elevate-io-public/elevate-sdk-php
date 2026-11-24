<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * A point in decimal degrees, stored to six decimal places.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Coordinates implements Model
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::float($data['latitude'] ?? null, 'latitude'),
            Decode::float($data['longitude'] ?? null, 'longitude'),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
