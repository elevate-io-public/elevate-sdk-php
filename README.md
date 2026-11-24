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
  `TokenClient` and `ApiClient`.

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
$connection->tokens;                // accessToken, refreshToken, expiresAt and scopes: store all four
```

## Call the API

`ApiClient` calls the API for one connection. It takes the stored tokens and a function that stores new ones:

```php
use Elevate\Api\ApiClient;
use Elevate\Auth\Tokens;

$apiClient = new ApiClient($config, $storedTokens, function (#[\SensitiveParameter] Tokens $tokens) use ($store): void {
    $store->replace($userId, $teamId, $tokens);
});

$team = $apiClient->teams->getTeam($teamId);
$team->name;
```

- The operations are grouped by the tags of the API specification: `teams`, `projects`, `media` and `auth`. They
  take and return the objects in `Elevate\Api\Model`.
- `ApiClient` refreshes the access token before it expires and when the API answers 401. It then calls the function
  with the new tokens.
- The function must store the tokens before it returns: the previous refresh token stops working after 60 seconds.
  If the function throws, read the tokens from `$apiClient->tokens()`.
- Run one refresh at a time for each connection.
- A failed refresh throws from the call. See [Errors](#errors).
- A response holds an enum value as a string, so that a new value in the API does not break the SDK. Compare it as
  in `$status->status === IngestStatus::Completed->value`.

## Refresh without `ApiClient`

To send your own API requests, refresh with `TokenClient`:

```php
$tokens = $tokenClient->refresh($storedTokens->refreshToken);

// Replace the stored tokens with $tokens.
```

## Errors

Every exception implements `Elevate\Exception\ElevateException`.

| Exception | Meaning | Action |
| --- | --- | --- |
| `TokenRequestException` | elevate.io refused the token request. `error` holds the OAuth2 error code and `statusCode` the HTTP status. | <ul><li>`isInvalidGrant()` on a refresh: the connection is revoked. Discard the refresh token and offer a reconnect.</li></ul> |
| `ApiException` | The API refused the request. `errorCode` holds the error code and `statusCode` the HTTP status. | <ul><li>`isNotGranted()`: the connection does not cover the resource, or the user revoked it. Keep the refresh token until a refresh fails with `isInvalidGrant()`.</li><li>`isPlanNotEligible()`: tell the user and keep the refresh token.</li><li>`isInvalidToken()`: report it to elevate.io.</li><li>Show `actionUrl` to the user when it is set.</li></ul> |
| `ValidationException` | The request is invalid. `errors` holds the `path` and the `code` of each invalid part. | Correct the request. |
| `TransportException` | The request failed in transit, or elevate.io failed with a status of 500 or more. `statusCode` holds the HTTP status, or null without a response. | <ul><li>Retry an API call that reads with backoff. A call that writes may have taken effect: check before sending it again.</li><li>Retry a refresh within 60 seconds, with the same refresh token.</li><li>Do not retry a code exchange: start a new authorization.</li></ul> |
| `UnexpectedResponseException` | The SDK cannot read the response. `statusCode` holds the HTTP status. | Report it to elevate.io. |

## Example

`examples/connect.php` runs the connect flow from a terminal.

## Development

```
composer install
composer test
composer analyse
composer style
```

### Generated code

`src/Api/Operations.php`, `src/Api/Operations` and `src/Api/Model` are generated from `openapi/openapi-spec.yaml`. Do
not edit them.

- `composer generate` writes them again.
- `ELEVATE_SPEC_URL=... composer sync-spec` fetches the specification from the URL, then generates.
- CI fails when the committed code differs from the generated code.
- The generator, `generator/Generator.php`, throws on a construct that it does not support, such as `allOf`.
