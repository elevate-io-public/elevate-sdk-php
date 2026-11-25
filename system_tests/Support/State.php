<?php

declare(strict_types=1);

namespace Elevate\SystemTests\Support;

/**
 * What the fake of elevate.io knows, kept in a file: the PHP development server runs the fake afresh for each request,
 * and the tests read and change the same file.
 */
final class State
{
    /** @var list<array{method: string, uri: string, body: string}> every request, in the order of arrival */
    public array $requests = [];

    /** @var list<string> the id of the operation of each API request, in the order of arrival */
    public array $operations = [];

    /** @var list<string> the message of each ContractViolation and of each error of the fake */
    public array $violations = [];

    /** @var array<string, array{challenge: string, redirectUri: string, scopes: list<string>, teamId: string, used: bool}> */
    public array $codes = [];

    /** @var array<string, array{teamId: string, scopes: list<string>, revoked: bool, refreshToken: string, replaced: array<string, int>}> */
    public array $grants = [];

    /** @var array<string, array{grantId: string, expiresAt: int}> */
    public array $accessTokens = [];

    /** @var array<string, true> */
    public array $assertionIds = [];

    /** @var array<string, list<array{id: string, name: string}>> the projects by team id */
    public array $projects = [];

    public int $lostResponses = 0;

    public int $sequence = 0;

    /**
     * @param string $url       the URL of the server, which is both the authorization server and the API
     * @param string $publicKey the PEM-encoded public key of the client, as the client's JWKS URL serves it
     * @param string $algorithm the algorithm of the client assertion
     * @param int    $now       the time of the fake, as a Unix timestamp
     */
    public function __construct(
        public readonly string $url,
        public readonly string $clientId,
        public readonly string $keyId,
        public readonly string $publicKey,
        public readonly string $algorithm,
        public int $now,
        public readonly int $accessTokenLifetime,
    ) {
    }

    public static function load(string $file): self
    {
        $handle = fopen($file, 'r');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('The state file %s is not readable.', $file));
        }
        flock($handle, \LOCK_SH);
        $state = unserialize((string) stream_get_contents($handle), ['allowed_classes' => [self::class]]);
        fclose($handle);
        if (!$state instanceof self) {
            throw new \RuntimeException(\sprintf('The state file %s does not hold the state of the fake.', $file));
        }

        return $state;
    }

    public function save(string $file): void
    {
        if (false === file_put_contents($file, serialize($this), \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('The state file %s is not writable.', $file));
        }
    }

    public function tokenUrl(): string
    {
        return $this->url.'/oauth2/token';
    }
}
