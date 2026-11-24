<?php

declare(strict_types=1);

namespace Elevate\Auth;

use Elevate\Clock;
use Elevate\Config;
use Firebase\JWT\JWT;

/**
 * Signs the JWT that authenticates the client at the token endpoint with `private_key_jwt`.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7523#section-3
 *
 * @internal
 */
final readonly class ClientAssertionSigner
{
    public const string ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private const int LIFETIME_SECONDS = 60;

    public function __construct(
        private Config $config,
        private Clock $clock,
    ) {
    }

    public function sign(): string
    {
        $issuedAt = $this->clock->now()->getTimestamp();

        return JWT::encode(
            [
                'iss' => $this->config->clientId,
                'sub' => $this->config->clientId,
                'aud' => $this->config->tokenUrl(),
                'jti' => bin2hex(random_bytes(16)),
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::LIFETIME_SECONDS,
            ],
            $this->config->privateKey(),
            $this->config->signingAlgorithm,
            $this->config->keyId,
        );
    }
}
