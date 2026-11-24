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
 * Calls the elevate.io token endpoint as the integrator's OAuth2 client.
 */
final readonly class TokenClient
{
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
