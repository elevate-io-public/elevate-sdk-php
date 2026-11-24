<?php

declare(strict_types=1);

namespace Elevate\Api;

/**
 * An object that the elevate.io API sends or receives.
 */
interface Model extends \JsonSerializable
{
    /**
     * @param array<string, mixed> $data the decoded JSON object
     *
     * @throws \Elevate\Exception\UnexpectedResponseException when a field is missing or has another type
     */
    public static function fromArray(array $data): static;

    public function jsonSerialize(): object;
}
