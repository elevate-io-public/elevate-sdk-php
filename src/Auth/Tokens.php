<?php

declare(strict_types=1);

namespace Elevate\Auth;

final readonly class Tokens
{
    /**
     * @param string       $accessToken  the bearer token for the elevate.io API
     * @param string       $refreshToken the single-use token that obtains the next access token
     * @param list<string> $scopes       the scopes that the access token carries
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        #[\SensitiveParameter]
        public string $refreshToken,
        public \DateTimeImmutable $expiresAt,
        public array $scopes,
    ) {
    }

    /**
     * Omits the tokens: var_dump and print_r print what this method returns.
     *
     * @return array{expiresAt: \DateTimeImmutable, scopes: list<string>}
     */
    public function __debugInfo(): array
    {
        return ['expiresAt' => $this->expiresAt, 'scopes' => $this->scopes];
    }
}
