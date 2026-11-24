<?php

declare(strict_types=1);

namespace Elevate\Auth;

use Elevate\Clock;
use Elevate\Config;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Http\CurlHttpClient;
use Elevate\Http\HttpClient;
use Elevate\Http\HttpClientException;
use Elevate\Http\Request;

/**
 * Builds the authorization request and calls the elevate.io token endpoint as the integrator's OAuth2 client.
 */
final readonly class TokenClient
{
    // Always requested: exchangeCode needs the ID token from `openid` and the refresh token from `offline_access`.
    private const array REQUIRED_SCOPES = ['openid', 'offline_access'];

    private HttpClient $httpClient;
    private Clock $clock;
    private ClientAssertionSigner $signer;
    private IdTokenReader $idTokenReader;

    public function __construct(
        private Config $config,
        ?HttpClient $httpClient = null,
        ?Clock $clock = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->clock = $clock ?? new SystemClock();
        $this->signer = new ClientAssertionSigner($config, $this->clock);
        $this->idTokenReader = new IdTokenReader($config, $this->clock);
    }

    /**
     * Builds the authorization request that connects a resource of the user to the integrator.
     *
     * Store the state and the code verifier in the user's session. When the redirect arrives, compare its `state`
     * query parameter with the stored state before the code exchange.
     *
     * @param string       $redirectUri a redirect URI that is registered for the client
     * @param list<string> $scopes      the resource selection scope and the API scopes, such as `select:team` and
     *                                  `teams:read`; the request always includes `openid` and `offline_access`
     */
    public function authorizationRequest(string $redirectUri, array $scopes): AuthorizationRequest
    {
        $state = self::base64Url(random_bytes(16));
        $codeVerifier = self::base64Url(random_bytes(32));

        return new AuthorizationRequest(
            $this->config->authUrl.'/oauth2/auth?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $this->config->clientId,
                'redirect_uri' => $redirectUri,
                'scope' => implode(' ', array_unique(array_filter([...self::REQUIRED_SCOPES, ...$scopes], static fn (string $name): bool => '' !== $name))),
                'audience' => $this->config->apiUrl,
                'state' => $state,
                'code_challenge' => self::base64Url(hash('sha256', $codeVerifier, true)),
                'code_challenge_method' => 'S256',
            ], encoding_type: \PHP_QUERY_RFC3986),
            $state,
            $codeVerifier,
        );
    }

    /**
     * Exchanges the authorization code from the redirect for tokens.
     *
     * The code is single use: when the exchange fails in transit, start a new authorization request.
     *
     * @param string $code         the `code` query parameter of the redirect
     * @param string $redirectUri  the redirect URI of the authorization request
     * @param string $codeVerifier the PKCE verifier of the authorization request
     *
     * @throws TokenRequestException       when elevate.io refuses the exchange
     * @throws TransportException          when the request fails in transit
     * @throws UnexpectedResponseException when the response is not a token response with an ID token
     */
    public function exchangeCode(
        #[\SensitiveParameter]
        string $code,
        string $redirectUri,
        #[\SensitiveParameter]
        string $codeVerifier,
    ): Connection {
        $response = $this->request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        $idToken = $response['id_token'] ?? null;
        if (!\is_string($idToken)) {
            throw new UnexpectedResponseException('The token response has no ID token. The authorization request must include the "openid" scope.');
        }
        if (!\is_string($response['refresh_token'] ?? null)) {
            throw new UnexpectedResponseException('The token response has no refresh token. The authorization request must include the "offline_access" scope.');
        }
        $claims = $this->idTokenReader->read($idToken);
        $resourceType = $claims['resource_type'] ?? null;
        $resourceId = $claims['resource_id'] ?? null;
        if (!\is_string($resourceType) || !\is_string($resourceId) || '' === $resourceType || '' === $resourceId) {
            throw new UnexpectedResponseException('The ID token does not name the connected resource.');
        }

        return new Connection($this->tokens($response), $resourceType, $resourceId);
    }

    /**
     * Obtains a new access token and a new refresh token.
     *
     * The refresh token is single use: store the returned one in its place. elevate.io accepts the previous refresh
     * token for 60 seconds more: retry a refresh that fails in transit within that time, with the same token.
     *
     * @throws TokenRequestException       when elevate.io refuses the refresh; `isInvalidGrant()` means the
     *                                     connection is revoked
     * @throws TransportException          when the request fails in transit
     * @throws UnexpectedResponseException when the response is not a token response
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken): Tokens
    {
        return $this->tokens($this->request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]));
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    private function request(#[\SensitiveParameter] array $parameters): array
    {
        $body = http_build_query($parameters + [
            'client_assertion_type' => ClientAssertionSigner::ASSERTION_TYPE,
            'client_assertion' => $this->signer->sign(),
        ]);
        $request = new Request('POST', $this->config->tokenUrl(), [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], $body);

        try {
            $response = $this->httpClient->send($request);
        } catch (HttpClientException $exception) {
            // Leaves the cause out of the chain: its trace holds the request, which carries the code or the refresh token.
            throw new TransportException(\sprintf('The token request failed in transit: %s (%s).', $exception->getMessage(), $exception::class));
        }

        $statusCode = $response->statusCode;
        $decoded = json_decode($response->body, true);
        $error = \is_array($decoded) ? ($decoded['error'] ?? null) : null;
        if (\is_string($error)) {
            $description = $decoded['error_description'] ?? null;

            throw new TokenRequestException($error, \is_string($description) ? $description : null, $statusCode);
        }
        if (200 !== $statusCode || !\is_array($decoded)) {
            throw new UnexpectedResponseException(\sprintf('The token endpoint returned status %d without a token response.', $statusCode), $statusCode);
        }

        return array_filter($decoded, \is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $response
     */
    private function tokens(#[\SensitiveParameter] array $response): Tokens
    {
        $accessToken = $response['access_token'] ?? null;
        $refreshToken = $response['refresh_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;
        $scope = $response['scope'] ?? '';
        if (!\is_string($accessToken) || '' === $accessToken || !\is_int($expiresIn) || !\is_string($scope)) {
            throw new UnexpectedResponseException('The token response is missing the access token or its lifetime.');
        }
        if (!\is_string($refreshToken) || '' === $refreshToken) {
            throw new UnexpectedResponseException('The token response has no refresh token.');
        }

        return new Tokens(
            $accessToken,
            $refreshToken,
            $this->clock->now()->modify(\sprintf('+%d seconds', $expiresIn)),
            array_values(array_filter(explode(' ', $scope), static fn (string $name): bool => '' !== $name)),
        );
    }
}
