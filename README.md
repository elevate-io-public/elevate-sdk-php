# elevate.io SDK for PHP

A PHP client for the elevate.io public API.

It requires PHP 8.3 or later with the `curl` and `openssl` extensions. It installs one package, `firebase/php-jwt`.

## Install

```
composer config repositories.elevate-sdk-php vcs https://github.com/elevate-io-public/elevate-sdk-php
composer require elevate-io/elevate-sdk-php
```

## Configure

```php
use Elevate\Auth\TokenClient;
use Elevate\Config;

$config = new Config(
    clientId: 'the client id that elevate.io issued',
    privateKey: file_get_contents('/path/to/private-key.pem'),
    keyId: 'the kid of that key in your JWKS',
);
$tokenClient = new TokenClient($config);
```

- The private key is an RSA key or a P-256 key in PEM format.
- The `authUrl` and `apiUrl` arguments select another environment.
- The SDK sends its requests with `curl`. To use your own HTTP client, pass an `Elevate\Http\HttpClient` to
  `TokenClient`.

## Start the authorization

```php
$request = $tokenClient->authorizationRequest($redirectUri, ['select:team', 'teams:read']);

// Store $request->state and $request->codeVerifier in the user's session.
// Open $request->url in a popup.
```

- `select:team` asks the user to choose a team to connect.
- The SDK adds the `openid` and `offline_access` scopes, PKCE and the `state` value.

## Exchange the authorization code

Your redirect endpoint receives `code` and `state`. Check `state` against the stored state, then exchange the code:

```php
$connection = $tokenClient->exchangeCode($code, $redirectUri, $codeVerifier);

$connection->resourceType;          // 'team'
$connection->resourceId;            // the id of the team that the user connected
$connection->tokens->accessToken;
$connection->tokens->refreshToken;  // store it for this user and resource
$connection->tokens->expiresAt;     // DateTimeImmutable
```

## Refresh the access token

```php
$tokens = $tokenClient->refresh($storedRefreshToken);

// Replace the stored refresh token with $tokens->refreshToken.
```

- Each refresh returns a new refresh token, and the previous one stops working after 60 seconds.
- Run one refresh at a time for each user and resource.

## Errors

Every exception implements `Elevate\Exception\ElevateException`.

| Exception | Meaning | Action |
| --- | --- | --- |
| `TokenRequestException` | elevate.io refused the token request. `error` holds the OAuth2 error code and `statusCode` the HTTP status. | <ul><li>`isInvalidGrant()` on a refresh: the connection is revoked. Discard the refresh token and offer a reconnect.</li><li>Treat a `statusCode` of 500 or more as a `TransportException`.</li></ul> |
| `TransportException` | The request failed in transit. | <ul><li>Retry a refresh within 60 seconds, with the same refresh token.</li><li>Do not retry a code exchange: start a new authorization.</li></ul> |
| `UnexpectedResponseException` | The response is not a token response. `statusCode` holds the HTTP status. | <ul><li>Treat a `statusCode` of 500 or more as a `TransportException`.</li><li>Report any other status to elevate.io.</li></ul> |

## Example

`examples/connect.php` runs the connect flow from a terminal.

## Development

```
composer install
composer test
composer analyse
composer style
```
