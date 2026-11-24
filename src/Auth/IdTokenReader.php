<?php

declare(strict_types=1);

namespace Elevate\Auth;

use Elevate\Clock;
use Elevate\Config;
use Elevate\Exception\UnexpectedResponseException;

/**
 * Reads the claims of an ID token that came straight from the token endpoint.
 *
 * It checks the issuer, the audience and the expiry, and not the signature: the TLS connection to the token
 * endpoint authenticates the issuer.
 *
 * @see https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation
 *
 * @internal
 */
final readonly class IdTokenReader
{
    public function __construct(
        private Config $config,
        private Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(#[\SensitiveParameter] string $idToken): array
    {
        $segments = explode('.', $idToken);
        if (3 !== \count($segments)) {
            throw new UnexpectedResponseException('The ID token is not a signed JWT.');
        }
        $json = base64_decode(strtr($segments[1], '-_', '+/'), true);
        $claims = false === $json ? null : json_decode($json, true);
        if (!\is_array($claims)) {
            throw new UnexpectedResponseException('The ID token payload is not a JSON object.');
        }
        /** @var array<string, mixed> $claims */
        if (($claims['iss'] ?? null) !== $this->config->authUrl) {
            throw new UnexpectedResponseException('The ID token names another issuer.');
        }
        if (!\in_array($this->config->clientId, (array) ($claims['aud'] ?? []), true)) {
            throw new UnexpectedResponseException('The ID token names another audience.');
        }
        $expiresAt = $claims['exp'] ?? null;
        if (!\is_int($expiresAt) || $expiresAt <= $this->clock->now()->getTimestamp()) {
            throw new UnexpectedResponseException('The ID token has expired.');
        }

        return $claims;
    }
}
