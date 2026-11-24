<?php

declare(strict_types=1);

namespace Elevate\Exception;

/**
 * The request did not reach elevate.io, or no response came back.
 *
 * Retry a refresh with the same refresh token within 60 seconds. A code exchange cannot be retried, because
 * elevate.io may have redeemed the code: start a new authorization request.
 */
final class TransportException extends \RuntimeException implements ElevateException
{
}
