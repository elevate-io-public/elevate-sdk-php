<?php

declare(strict_types=1);

namespace Elevate\SystemTests\Support;

use Elevate\Auth\ClientAssertionSigner;
use Elevate\Http\Request;
use Elevate\Http\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Stands in for elevate.io: the token endpoint and the API.
 *
 * - The API side follows the specification. It checks each request against the specification and builds each
 *   response from the response schema. It answers 400 to a value that a schema refuses, and throws a
 *   ContractViolation at a request that the specification does not describe.
 * - The token endpoint has no specification. It follows OAuth2 with PKCE and `private_key_jwt`, with a rotating
 *   refresh token.
 * - The error codes of a 400 response name the schema keyword that the value fails. They are not the codes of
 *   elevate.io.
 */
final readonly class FakeElevate
{
    // Keeps a replaced refresh token valid for this long, as elevate.io does: a client whose refresh response was lost
    // refreshes again with the same token.
    public const int REFRESH_GRACE_SECONDS = 60;
    public const string TEAM_NAME = 'Newsroom';

    public function __construct(
        private State $state,
        private Specification $specification,
    ) {
    }

    /**
     * Answers a request that the server received.
     */
    public function receive(Request $request): Response
    {
        return match (true) {
            $request->url === $this->state->tokenUrl() => $this->token($request),
            str_starts_with($request->url, $this->state->url.'/') => $this->api($request),
            default => throw new ContractViolation(\sprintf('The request addresses %s, which is neither the token endpoint nor the API.', $request->url)),
        };
    }

    private function token(Request $request): Response
    {
        if ('POST' !== $request->method || 'application/x-www-form-urlencoded' !== self::header($request, 'Content-Type')) {
            throw new ContractViolation('The token request is not a form POST.');
        }
        parse_str($request->body, $form);
        $assertion = $form['client_assertion'] ?? null;
        if (ClientAssertionSigner::ASSERTION_TYPE !== ($form['client_assertion_type'] ?? null) || !\is_string($assertion) || !$this->isAssertionValid($assertion)) {
            return self::oauthError(401, 'invalid_client');
        }

        switch ($form['grant_type'] ?? null) {
            case 'authorization_code':
                $code = $form['code'] ?? null;
                $verifier = $form['code_verifier'] ?? null;
                $record = \is_string($code) ? ($this->state->codes[$code] ?? null) : null;
                if (
                    null === $record
                    || $record['used']
                    || !\is_string($verifier)
                    || $record['redirectUri'] !== ($form['redirect_uri'] ?? null)
                    || !hash_equals($record['challenge'], self::base64Url(hash('sha256', $verifier, true)))
                ) {
                    return self::oauthError(400, 'invalid_grant');
                }
                $this->state->codes[(string) $code]['used'] = true;
                $grantId = $this->secret('grant');
                $this->state->grants[$grantId] = ['teamId' => $record['teamId'], 'scopes' => $record['scopes'], 'revoked' => false, 'refreshToken' => '', 'replaced' => []];

                return $this->issue($grantId, withIdToken: true);
            case 'refresh_token':
                $refreshToken = $form['refresh_token'] ?? null;
                foreach ($this->state->grants as $grantId => $grant) {
                    $isCurrent = $grant['refreshToken'] === $refreshToken;
                    $isInGrace = \is_string($refreshToken) && ($grant['replaced'][$refreshToken] ?? \PHP_INT_MIN) + self::REFRESH_GRACE_SECONDS >= $this->state->now;
                    if (!$grant['revoked'] && '' !== $refreshToken && ($isCurrent || $isInGrace)) {
                        return $this->issue($grantId, withIdToken: false);
                    }
                }

                return self::oauthError(400, 'invalid_grant');
            default:
                return self::oauthError(400, 'unsupported_grant_type');
        }
    }

    private function isAssertionValid(string $assertion): bool
    {
        // Verifies at the time of the fake: the library reads the system time otherwise.
        JWT::$timestamp = $this->state->now;
        try {
            $claims = (array) JWT::decode($assertion, [$this->state->keyId => new Key($this->state->publicKey, $this->state->algorithm)]);
        } catch (\UnexpectedValueException) {
            return false;
        } finally {
            JWT::$timestamp = null;
        }
        $id = $claims['jti'] ?? null;
        if (
            $this->state->clientId !== ($claims['iss'] ?? null)
            || $this->state->clientId !== ($claims['sub'] ?? null)
            || !\in_array($this->state->tokenUrl(), (array) ($claims['aud'] ?? []), true)
            || !isset($claims['exp'])
            || !\is_string($id)
            || isset($this->state->assertionIds[$id])
        ) {
            return false;
        }
        $this->state->assertionIds[$id] = true;

        return true;
    }

    private function issue(string $grantId, bool $withIdToken): Response
    {
        $grant = $this->state->grants[$grantId];
        $accessToken = $this->secret('access');
        $this->state->accessTokens[$accessToken] = ['grantId' => $grantId, 'expiresAt' => $this->state->now + $this->state->accessTokenLifetime];

        $body = [
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => $this->state->accessTokenLifetime,
            'scope' => implode(' ', $grant['scopes']),
        ];
        if (\in_array('offline_access', $grant['scopes'], true)) {
            if ('' !== $grant['refreshToken']) {
                $this->state->grants[$grantId]['replaced'][$grant['refreshToken']] ??= $this->state->now;
            }
            $body['refresh_token'] = $this->state->grants[$grantId]['refreshToken'] = $this->secret('refresh');
        }
        if ($withIdToken && \in_array('openid', $grant['scopes'], true)) {
            $body['id_token'] = JWT::encode([
                'iss' => $this->state->url,
                'sub' => 'the-user',
                'aud' => [$this->state->clientId],
                'iat' => $this->state->now,
                'exp' => $this->state->now + 3600,
                'resource_type' => 'team',
                'resource_id' => $grant['teamId'],
            ], $this->secret('id-token-key'), 'HS256');
        }

        return self::json(200, $body);
    }

    private function api(Request $request): Response
    {
        $relative = substr($request->url, \strlen($this->state->url));
        $match = $this->specification->match($request->method, (string) parse_url($relative, \PHP_URL_PATH));
        if (null === $match) {
            throw new ContractViolation(\sprintf('The specification has no operation for %s %s.', $request->method, $relative));
        }
        [$operation, $pathParameters] = $match;
        $this->state->operations[] = $operation->id;
        if ('application/json' !== self::header($request, 'Accept')) {
            throw new ContractViolation(\sprintf('The request of %s does not accept application/json.', $operation->id));
        }

        $grantId = null;
        $scopes = $this->specification->scopes($operation);
        if (null !== $scopes) {
            $authorization = self::header($request, 'Authorization') ?? '';
            $token = str_starts_with($authorization, 'Bearer ') ? ($this->state->accessTokens[substr($authorization, 7)] ?? null) : null;
            if (null === $token || $token['expiresAt'] <= $this->state->now || $this->state->grants[$token['grantId']]['revoked']) {
                return $this->respond($operation, 401, ['code' => 'INVALID_TOKEN']);
            }
            $grantId = $token['grantId'];
            foreach ($scopes as $scope) {
                if (!\in_array($scope, $this->state->grants[$grantId]['scopes'], true)) {
                    return $this->respond($operation, 403, ['code' => 'INSUFFICIENT_SCOPE']);
                }
            }
        }

        $errors = $this->specification->violations($operation, $request);
        if ([] !== $errors) {
            return $this->respond($operation, 400, $errors);
        }
        $teamId = $pathParameters['teamId'] ?? null;
        if (null !== $grantId && null !== $teamId && $this->state->grants[$grantId]['teamId'] !== $teamId) {
            return $this->respond($operation, 403, ['code' => 'INTEGRATOR_NOT_GRANTED']);
        }

        parse_str((string) parse_url($relative, \PHP_URL_QUERY), $query);
        $body = json_decode($request->body);
        $statusCode = $this->specification->successStatus($operation);

        return $this->respond($operation, $statusCode, $this->handle(
            $operation,
            $grantId,
            $pathParameters,
            $query,
            $body instanceof \stdClass ? $body : null,
            $this->specification->example($operation, $statusCode),
        ));
    }

    /**
     * Fills the response that the schema gives with the state of the fake.
     *
     * @param array<string, string>        $pathParameters
     * @param array<array-key, mixed>      $query
     * @param array<array-key, mixed>|null $example        the response built from the schema
     *
     * @return array<array-key, mixed>|null
     */
    private function handle(Operation $operation, ?string $grantId, array $pathParameters, array $query, ?\stdClass $body, ?array $example): ?array
    {
        $teamId = $pathParameters['teamId'] ?? '';
        if (isset($example['mediaId'])) {
            $example['mediaId'] = $pathParameters['mediaId'] ?? $this->uuid();
        }

        switch ($operation->id) {
            case 'getTeam':
                return ['id' => $teamId, 'name' => self::TEAM_NAME] + ($example ?? []);
            case 'createProject':
                $project = ['id' => $this->uuid(), 'name' => \is_string($body?->name) ? $body->name : ''];
                $this->state->projects[$teamId][] = $project;

                return ['project' => $project] + ($example ?? []);
            case 'listProjects':
                $item = \is_array($example['items'] ?? null) && \is_array($example['items'][0] ?? null) ? $example['items'][0] : [];
                unset($example['nextCursor']);
                // Uses the offset of the next page as the cursor.
                $after = $query['after'] ?? '0';
                if (!\is_string($after) || !ctype_digit($after)) {
                    throw new ContractViolation('The request of listProjects has a cursor that the fake did not return.');
                }
                $projects = $this->state->projects[$teamId] ?? [];
                $page = \array_slice($projects, (int) $after, \is_string($query['limit'] ?? null) ? (int) $query['limit'] : null);
                $next = (int) $after + \count($page);
                $hasMoreAfter = $next < \count($projects);

                return [
                    'items' => array_map(static fn (array $project): array => ['project' => $project] + $item, $page),
                    'hasMoreAfter' => $hasMoreAfter,
                ] + ($hasMoreAfter ? ['nextCursor' => (string) $next] : []) + ($example ?? []);
            case 'revokeGrant':
                $this->state->grants[(string) $grantId]['revoked'] = true;

                return $example;
            default:
                return $example;
        }
    }

    /**
     * @param array<array-key, mixed>|null $body
     */
    private function respond(Operation $operation, int $statusCode, ?array $body): Response
    {
        $response = null === $body
            ? new Response($statusCode, [], '')
            : new Response($statusCode, ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
        $this->specification->check($operation, $response);

        return $response;
    }

    private function secret(string $kind): string
    {
        return $kind.'-'.bin2hex(random_bytes(16));
    }

    private function uuid(): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', ++$this->state->sequence);
    }

    private static function header(Request $request, string $name): ?string
    {
        foreach ($request->headers as $key => $value) {
            if (0 === strcasecmp($key, $name)) {
                return $value;
            }
        }

        return null;
    }

    private static function oauthError(int $statusCode, string $error): Response
    {
        return self::json($statusCode, ['error' => $error, 'error_description' => 'The fake refuses the token request.']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(int $statusCode, array $body): Response
    {
        return new Response($statusCode, ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
