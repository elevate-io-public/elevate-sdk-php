<?php

declare(strict_types=1);

namespace Elevate;

use Elevate\Exception\InvalidConfigException;

/**
 * The integrator's OAuth2 client, as registered with elevate.io.
 */
final readonly class Config
{
    public const string PRODUCTION_AUTH_URL = 'https://auth.elevate.io';
    public const string PRODUCTION_API_URL = 'https://api.elevate.io';

    public string $authUrl;

    public string $apiUrl;

    /** The JWS algorithm that the private key signs with: RS256 or ES256. */
    public string $signingAlgorithm;

    /**
     * @param string $clientId   the OAuth2 client id that elevate.io issued
     * @param string $privateKey the PEM-encoded RSA or P-256 private key whose public key the client's JWKS URL serves
     * @param string $keyId      the `kid` of that key in the JWKS
     * @param string $authUrl    the elevate.io authorization server
     * @param string $apiUrl     the elevate.io API
     */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        private string $privateKey,
        public string $keyId,
        string $authUrl = self::PRODUCTION_AUTH_URL,
        string $apiUrl = self::PRODUCTION_API_URL,
    ) {
        if ('' === $clientId) {
            throw new InvalidConfigException('The client id is empty.');
        }
        if ('' === $keyId) {
            throw new InvalidConfigException('The key id is empty.');
        }
        if (!self::isHttpsUrl($authUrl)) {
            throw new InvalidConfigException('The authorization server URL is not an https URL.');
        }
        if (!self::isHttpsUrl($apiUrl)) {
            throw new InvalidConfigException('The API URL is not an https URL.');
        }
        $key = openssl_pkey_get_private($privateKey);
        if (false === $key) {
            throw new InvalidConfigException('The private key is not a PEM-encoded private key.');
        }
        $this->signingAlgorithm = self::signingAlgorithmOf($key);

        $this->authUrl = rtrim($authUrl, '/');
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    public function tokenUrl(): string
    {
        return $this->authUrl.'/oauth2/token';
    }

    public function privateKey(): string
    {
        return $this->privateKey;
    }

    /**
     * Omits the private key: var_dump and print_r print what this method returns.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'keyId' => $this->keyId,
            'authUrl' => $this->authUrl,
            'apiUrl' => $this->apiUrl,
            'signingAlgorithm' => $this->signingAlgorithm,
        ];
    }

    private static function isHttpsUrl(string $url): bool
    {
        return 'https' === parse_url($url, \PHP_URL_SCHEME) && \is_string(parse_url($url, \PHP_URL_HOST));
    }

    private static function signingAlgorithmOf(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (false === $details) {
            throw new InvalidConfigException('The private key is not readable.');
        }
        $ec = $details['ec'] ?? null;

        return match (true) {
            \OPENSSL_KEYTYPE_RSA === $details['type'] => 'RS256',
            \is_array($ec) && 'prime256v1' === ($ec['curve_name'] ?? null) => 'ES256',
            default => throw new InvalidConfigException('The private key is neither an RSA key nor a P-256 elliptic curve key.'),
        };
    }
}
