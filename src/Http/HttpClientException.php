<?php

declare(strict_types=1);

namespace Elevate\Http;

/**
 * An HttpClient throws it when no response arrives. The SDK reports it as a TransportException.
 */
final class HttpClientException extends \RuntimeException
{
}
