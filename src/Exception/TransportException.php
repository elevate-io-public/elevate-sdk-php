<?php

declare(strict_types=1);

namespace Elevate\Exception;

/**
 * The request did not reach elevate.io, no response came back, or elevate.io failed with a status of 500 or more.
 *
 * Retry an API call that reads with backoff. A call that writes may have taken effect: check before sending it again.
 * Retry a refresh with the same refresh token within 60 seconds. A code exchange
 * cannot be retried, because elevate.io may have redeemed the code: start a new authorization request.
 */
final class TransportException extends \RuntimeException implements ElevateException
{
    /**
     * @param int|null $statusCode the HTTP status of the response, or null when no response arrived
     */
    public function __construct(string $message, public readonly ?int $statusCode = null)
    {
        parent::__construct($message);
    }
}
