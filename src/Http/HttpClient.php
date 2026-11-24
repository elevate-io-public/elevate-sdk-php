<?php

declare(strict_types=1);

namespace Elevate\Http;

/**
 * Sends the requests of the SDK. Implement it to send them through your own HTTP client.
 */
interface HttpClient
{
    /**
     * Returns the response whatever its status, with its headers, and does not follow a redirect.
     *
     * The request carries credentials: do not log its headers or its body.
     *
     * @throws HttpClientException when no response arrives
     */
    public function send(Request $request): Response;
}
