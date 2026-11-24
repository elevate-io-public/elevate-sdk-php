<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class PartUrl implements Model
{
    public function __construct(
        /**
         * The part number. The first part is 1.
         */
        public int $partNumber,
        /**
         * The presigned URL to upload the part to.
         */
        public string $url,
        /**
         * The HTTP method of the upload request.
         */
        public string $method,
        /**
         * The HTTP headers to send with the upload request.
         *
         * @var array<string, list<string>>
         */
        public array $headers,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::int($data['partNumber'] ?? null, 'partNumber'),
            Decode::string($data['url'] ?? null, 'url'),
            Decode::string($data['method'] ?? null, 'method'),
            array_map(static fn (mixed $item): array => array_map(static fn (mixed $item2): string => Decode::string($item2, 'headers[][]'), Decode::list($item, 'headers[]')), Decode::map($data['headers'] ?? null, 'headers')),
        );
    }

    public function jsonSerialize(): object
    {
        return (object) [
            'partNumber' => $this->partNumber,
            'url' => $this->url,
            'method' => $this->method,
            'headers' => (object) $this->headers,
        ];
    }
}
