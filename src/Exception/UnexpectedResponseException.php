<?php

declare(strict_types=1);

namespace Elevate\Exception;

/**
 * elevate.io returned a response that the SDK cannot read.
 *
 * A `statusCode` of 500 or more is a server failure: handle it as a TransportException.
 */
final class UnexpectedResponseException extends \RuntimeException implements ElevateException
{
    /**
     * @param int $statusCode the HTTP status of the response
     */
    public function __construct(string $message, public readonly int $statusCode = 200)
    {
        parent::__construct($message);
    }
}
