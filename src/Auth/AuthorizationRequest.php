<?php

declare(strict_types=1);

namespace Elevate\Auth;

/**
 * An authorization request to open in the user's browser, and the two values to keep until the redirect arrives.
 */
final readonly class AuthorizationRequest
{
    /**
     * @param string $url          the URL to open in the browser
     * @param string $state        the value that the redirect must return in its `state` query parameter
     * @param string $codeVerifier the PKCE verifier to pass to `TokenClient::exchangeCode`
     */
    public function __construct(
        public string $url,
        public string $state,
        #[\SensitiveParameter]
        public string $codeVerifier,
    ) {
    }
}
