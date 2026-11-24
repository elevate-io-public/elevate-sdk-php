<?php

declare(strict_types=1);

namespace Elevate\Tests;

use Elevate\Http\HttpClient;
use Elevate\Http\HttpClientException;
use Elevate\Http\Request;
use Elevate\Http\Response;

/**
 * Returns queued responses and keeps the requests it received.
 */
final class RecordingHttpClient implements HttpClient
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|HttpClientException> */
    private array $queue = [];

    /**
     * @param array<string, mixed> $body
     */
    public function willReturnJson(int $statusCode, array $body): void
    {
        $this->queue[] = new Response($statusCode, [], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function willReturn(int $statusCode, string $body): void
    {
        $this->queue[] = new Response($statusCode, [], $body);
    }

    public function willThrow(HttpClientException $exception): void
    {
        $this->queue[] = $exception;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new \LogicException('No response is queued.');
        if ($next instanceof HttpClientException) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function lastForm(): array
    {
        $request = $this->requests[array_key_last($this->requests) ?? throw new \LogicException('No request was sent.')];
        parse_str($request->body, $form);

        return $form;
    }
}
