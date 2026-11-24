<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

use Elevate\Api\Decode;
use Elevate\Api\Model;

/**
 * Why the ingest failed. Present when the status is FAILED.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class IngestFailure implements Model
{
    public function __construct(
        /**
         * The failure code.
         */
        public string $code,
        /**
         * A description of the failure for the integrator's logs. Do not show it to the user.
         */
        public ?string $message = null,
        /**
         * Present when the user can resolve the failure in elevate.io. It links to the elevate.io page where the
         * user resolves it. Show the link to the user.
         */
        public ?string $actionUrl = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Decode::string($data['code'] ?? null, 'code'),
            isset($data['message']) ? Decode::string($data['message'], 'message') : null,
            isset($data['actionUrl']) ? Decode::string($data['actionUrl'], 'actionUrl') : null,
        );
    }

    public function jsonSerialize(): object
    {
        return (object) array_filter([
            'code' => $this->code,
            'message' => $this->message,
            'actionUrl' => $this->actionUrl,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
