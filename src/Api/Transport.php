<?php

declare(strict_types=1);

namespace Elevate\Api;

use Elevate\Auth\TokenClient;
use Elevate\Auth\Tokens;
use Elevate\Clock;
use Elevate\Config;
use Elevate\Exception\ApiException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;
use Elevate\Http\HttpClient;
use Elevate\Http\HttpClientException;
use Elevate\Http\Request;
use Elevate\Http\Response;

/**
 * Sends the requests of the generated operations for one connection: it adds the access token, refreshes it when it
 * has to, and turns an error response into an exception.
 *
 * @internal
 */
final class Transport
{
    private const string VISIBLE_ASCII = '/\A[\x21-\x7E]+\z/';
    // Refreshes this long before the expiry: the access token must still be valid when the request arrives.
    private const int EXPIRY_MARGIN_SECONDS = 30;

    private readonly TokenClient $tokenClient;

    /**
     * @param \Closure(Tokens): void $onRefresh
     */
    public function __construct(
        private readonly Config $config,
        #[\SensitiveParameter]
        private Tokens $tokens,
        private readonly \Closure $onRefresh,
        private readonly HttpClient $httpClient,
        private readonly Clock $clock,
    ) {
        $this->tokenClient = new TokenClient($config, $httpClient, $clock);
    }

    public function tokens(): Tokens
    {
        return $this->tokens;
    }

    /**
     * @template T of Model
     *
     * @param array<string, string>               $pathParameters
     * @param array<string, string|int|bool|null> $query
     * @param class-string<T>|null                $responseClass
     *
     * @return ($responseClass is null ? null : T)
     */
    public function call(string $method, string $path, array $pathParameters, array $query, ?Model $body, ?string $responseClass): ?Model
    {
        foreach ($pathParameters as $name => $value) {
            // Refuses a value that changes the route: an empty segment, or a dot segment that a server resolves.
            if (\in_array($value, ['', '.', '..'], true)) {
                throw new ValidationException([['code' => 'INVALID_PATH_PARAMETER', 'path' => $name]]);
            }
            $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
        }
        $query = array_map(
            static fn (string|int|bool $value): string|int => \is_bool($value) ? ($value ? 'true' : 'false') : $value,
            array_filter($query, static fn (string|int|bool|null $value): bool => null !== $value),
        );
        $headers = ['Accept' => 'application/json'] + (null === $body ? [] : ['Content-Type' => 'application/json']);
        try {
            $encoded = null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException([['code' => 'NOT_JSON_ENCODABLE', 'path' => 'body']]);
        }
        $request = new Request(
            $method,
            $this->config->apiUrl.$path.([] === $query ? '' : '?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986)),
            $headers,
            $encoded ?? '',
        );

        $refreshed = false;
        if (!$this->isAccessTokenUsable() || $this->tokens->expiresAt <= $this->clock->now()->modify(\sprintf('+%d seconds', self::EXPIRY_MARGIN_SECONDS))) {
            $this->refresh();
            $refreshed = true;
        }
        $response = $this->send($request);
        // Refreshes once on a 401: elevate.io can end an access token before its expiry.
        if (401 === $response->statusCode && !$refreshed) {
            $this->refresh();
            $response = $this->send($request);
        }

        $statusCode = $response->statusCode;
        $decoded = json_decode($response->body, true);
        if ($statusCode >= 200 && $statusCode < 300) {
            if (null === $responseClass) {
                return null;
            }
            try {
                return $responseClass::fromArray(Decode::map($decoded, 'body'));
            } catch (UnexpectedResponseException $exception) {
                throw new UnexpectedResponseException($exception->getMessage(), $statusCode);
            }
        }
        if (400 === $statusCode && \is_array($decoded) && [] !== ($errors = self::validationErrors($decoded))) {
            throw new ValidationException($errors);
        }
        $errorCode = \is_array($decoded) ? ($decoded['code'] ?? null) : null;
        if (!\is_string($errorCode)) {
            throw new UnexpectedResponseException(\sprintf('The API returned status %d without an error code.', $statusCode), $statusCode);
        }
        $detail = $decoded['message'] ?? null;
        $actionUrl = $decoded['actionUrl'] ?? null;

        throw new ApiException($errorCode, \is_string($detail) ? $detail : null, \is_string($actionUrl) ? $actionUrl : null, $statusCode);
    }

    /**
     * Omits the tokens: var_dump and print_r print what this method returns.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['apiUrl' => $this->config->apiUrl];
    }

    private function send(Request $request): Response
    {
        try {
            return $this->httpClient->send($request->withHeader('Authorization', 'Bearer '.$this->tokens->accessToken));
        } catch (HttpClientException $exception) {
            // Leaves the cause out of the chain: its trace holds the request, which carries the access token.
            throw new TransportException(\sprintf('The API request failed in transit: %s (%s).', $exception->getMessage(), $exception::class));
        }
    }

    private function refresh(): void
    {
        $this->tokens = $this->tokenClient->refresh($this->tokens->refreshToken);
        // Hands the tokens over before anything else can throw: the previous refresh token is spent.
        ($this->onRefresh)($this->tokens);
        if (!$this->isAccessTokenUsable()) {
            throw new UnexpectedResponseException('The token response has an access token with a character outside visible ASCII.');
        }
    }

    /**
     * Keeps a token outside visible ASCII out of the Authorization header: a line break in it would add a header to
     * the request.
     */
    private function isAccessTokenUsable(): bool
    {
        return 1 === preg_match(self::VISIBLE_ASCII, $this->tokens->accessToken);
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<array{code: string, path: string}>
     */
    private static function validationErrors(array $body): array
    {
        $errors = [];
        foreach (array_is_list($body) ? $body : [] as $error) {
            $code = \is_array($error) ? ($error['code'] ?? null) : null;
            $path = \is_array($error) ? ($error['path'] ?? null) : null;
            if (\is_string($code) && \is_string($path)) {
                $errors[] = ['code' => $code, 'path' => $path];
            }
        }

        return $errors;
    }
}
