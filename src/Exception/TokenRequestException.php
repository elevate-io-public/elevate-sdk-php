<?php

declare(strict_types=1);

namespace Elevate\Exception;

/**
 * The token endpoint refused the request with an OAuth2 error.
 *
 * A `statusCode` of 500 or more is a server failure: handle it as a TransportException.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6749#section-5.2
 */
final class TokenRequestException extends \RuntimeException implements ElevateException
{
    public function __construct(
        public readonly string $error,
        public readonly ?string $errorDescription,
        public readonly int $statusCode,
    ) {
        parent::__construct(
            null === $errorDescription
                ? \sprintf('The token endpoint returned "%s".', $error)
                : \sprintf('The token endpoint returned "%s": %s', $error, $errorDescription),
        );
    }
}
