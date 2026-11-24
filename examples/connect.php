<?php

declare(strict_types=1);

/*
 * Runs the connect flow from a terminal: prints the authorization URL, takes the redirect URL that the browser
 * ends on, exchanges the code, reads the connected team and shows a refresh.
 *
 * Usage:
 *   ELEVATE_CLIENT_ID=... ELEVATE_KEY_ID=... ELEVATE_PRIVATE_KEY_FILE=private.pem \
 *   ELEVATE_REDIRECT_URI=https://integrator.example.com/elevate/callback php examples/connect.php
 *
 * ELEVATE_AUTH_URL, ELEVATE_API_URL and ELEVATE_SCOPE override the production defaults.
 */

use Elevate\Api\ApiClient;
use Elevate\Auth\TokenClient;
use Elevate\Auth\Tokens;
use Elevate\Config;

require __DIR__.'/../vendor/autoload.php';

function env(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if (false === $value || '' === $value) {
        if (null === $default) {
            fwrite(\STDERR, "Set {$name}.\n");
            exit(2);
        }

        return $default;
    }

    return $value;
}

$privateKey = file_get_contents(env('ELEVATE_PRIVATE_KEY_FILE'));
if (false === $privateKey) {
    fwrite(\STDERR, "The private key file is not readable.\n");
    exit(2);
}
$config = new Config(
    env('ELEVATE_CLIENT_ID'),
    $privateKey,
    env('ELEVATE_KEY_ID'),
    env('ELEVATE_AUTH_URL', Config::PRODUCTION_AUTH_URL),
    env('ELEVATE_API_URL', Config::PRODUCTION_API_URL),
);
$redirectUri = env('ELEVATE_REDIRECT_URI');

$tokenClient = new TokenClient($config);
$request = $tokenClient->authorizationRequest($redirectUri, explode(' ', env('ELEVATE_SCOPE', 'select:team teams:read')));

echo "Open this URL, sign in and choose a team:\n\n{$request->url}\n\n";
echo 'Paste the URL that the browser was redirected to: ';

parse_str((string) parse_url(trim((string) fgets(\STDIN)), \PHP_URL_QUERY), $query);
if (($query['state'] ?? null) !== $request->state) {
    fwrite(\STDERR, "The state does not match.\n");
    exit(1);
}
if (isset($query['error'])) {
    fwrite(\STDERR, 'The authorization failed: '.json_encode($query['error'])."\n");
    exit(1);
}
if (!is_string($query['code'] ?? null)) {
    fwrite(\STDERR, "The redirect URL carries no code.\n");
    exit(1);
}

$connection = $tokenClient->exchangeCode($query['code'], $redirectUri, $request->codeVerifier);
echo "\nConnected {$connection->resourceType} {$connection->resourceId}.\n";
echo 'Scopes: '.implode(' ', $connection->tokens->scopes)."\n";
echo 'The access token expires at '.$connection->tokens->expiresAt->format(\DATE_ATOM).".\n";

// An integrator stores the tokens for the user and the resource here, in place of the previous ones.
$store = static function (#[SensitiveParameter] Tokens $tokens): void {
    echo 'Refreshed. The new access token expires at '.$tokens->expiresAt->format(\DATE_ATOM).".\n";
};

$apiClient = new ApiClient($config, $connection->tokens, $store);
$team = $apiClient->teams->getTeam($connection->resourceId);
echo "The connected team is \"{$team->name}\".\n";

// Passes the tokens as expired, so that the next call refreshes first.
$expired = new Tokens(
    $apiClient->tokens()->accessToken,
    $apiClient->tokens()->refreshToken,
    new DateTimeImmutable('-1 minute'),
    $apiClient->tokens()->scopes,
);
$apiClient = new ApiClient($config, $expired, $store);
$team = $apiClient->teams->getTeam($connection->resourceId);
echo "After the refresh, the connected team is still \"{$team->name}\".\n";
echo 'The refresh token '.($apiClient->tokens()->refreshToken === $connection->tokens->refreshToken ? 'did not change' : 'changed').".\n";
