<?php

declare(strict_types=1);

namespace Elevate\E2eTests\Support;

/**
 * An elevate.io user who signs in with a password and connects a team, over HTTP in place of a browser.
 *
 * The messages of its exceptions hold no text of a response: the log of the workflow is public. They name the step,
 * the status, the known states of the teams and an OAuth2 error code in the form of one.
 */
final class ElevateUser
{
    private const int MAX_REDIRECTS = 5;
    private const array TEAM_STATES = ['NOT_PERMITTED', 'ALREADY_CONNECTED', 'PLAN_INELIGIBLE', 'CONNECTABLE'];

    private readonly string $authUrl;
    private readonly string $identityApiUrl;
    private readonly \SensitiveParameterValue $password;
    private readonly \CurlHandle $curl;

    public function __construct(
        string $authUrl,
        string $identityApiUrl,
        private readonly string $email,
        #[\SensitiveParameter]
        string $password,
    ) {
        $this->authUrl = rtrim($authUrl, '/');
        $this->identityApiUrl = rtrim($identityApiUrl, '/');
        $this->password = new \SensitiveParameterValue($password);
        $this->curl = curl_init();
    }

    /**
     * Opens the authorization URL, signs in and connects the one team that the user can connect.
     *
     * @return array{teamId: string, redirectUrl: string} the connected team, and the redirect to the integrator
     */
    public function connect(string $authorizationUrl): array
    {
        $loginChallenge = $this->redirectWith($authorizationUrl, 'login_challenge')['value'];
        $consentChallenge = $this->redirectWith($this->signIn($loginChallenge), 'consent_challenge')['value'];

        $token = $this->apiToken();
        // Leaves the challenge unencoded in the path: the API does not decode the path segment.
        $consentUrl = $this->identityApiUrl.'/oauth2/consent/'.$consentChallenge;
        $teamId = $this->connectableTeam($consentUrl, $token);
        $accepted = $this->json('accept the consent', 'POST', $consentUrl.'/accept', $token, ['resourceId' => $teamId]);
        $redirectTo = $accepted['redirectTo'] ?? null;
        if (!\is_string($redirectTo)) {
            throw new \RuntimeException('The accepted consent has no redirect.');
        }

        return ['teamId' => $teamId, 'redirectUrl' => $this->redirectWith($redirectTo, 'code')['url']];
    }

    /**
     * @return string the URL that returns the signed-in user to the authorization server
     */
    private function signIn(string $loginChallenge): string
    {
        $flow = $this->json('start the login', 'GET', $this->authUrl.'/self-service/login/browser?'.http_build_query(['login_challenge' => $loginChallenge]));
        $flowId = $flow['id'] ?? null;
        if (!\is_string($flowId)) {
            throw new \RuntimeException('The login flow has no id.');
        }

        // Expects 422: the authorization server answers a JSON client with the next URL, where a browser gets a redirect.
        $login = $this->json('submit the password', 'POST', $this->authUrl.'/self-service/login?'.http_build_query(['flow' => $flowId]), null, [
            'method' => 'password',
            'identifier' => $this->email,
            'password' => $this->password->getValue(),
            'csrf_token' => self::csrfToken($flow),
        ], 422);
        $next = $login['redirect_browser_to'] ?? null;
        if (!\is_string($next)) {
            throw new \RuntimeException('The login returned no URL to continue at.');
        }

        return $next;
    }

    /**
     * @param array<mixed> $flow
     */
    private static function csrfToken(array $flow): string
    {
        $ui = $flow['ui'] ?? null;
        $nodes = \is_array($ui) ? ($ui['nodes'] ?? null) : null;
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            $attributes = \is_array($node) ? ($node['attributes'] ?? null) : null;
            if (\is_array($attributes) && 'csrf_token' === ($attributes['name'] ?? null) && \is_string($attributes['value'] ?? null)) {
                return $attributes['value'];
            }
        }

        throw new \RuntimeException('The login flow has no CSRF token.');
    }

    /**
     * @return string a short-lived access token of the signed-in user for the identity API
     */
    private function apiToken(): string
    {
        $session = $this->json('read the session', 'GET', $this->authUrl.'/sessions/whoami?tokenize_as=api-bearer-token');
        $token = $session['tokenized'] ?? null;
        if (!\is_string($token)) {
            throw new \RuntimeException('The session has no token.');
        }

        return $token;
    }

    private function connectableTeam(string $consentUrl, #[\SensitiveParameter] string $token): string
    {
        $teams = $this->json('list the teams', 'GET', $consentUrl.'/teams?limit=100', $token)['items'] ?? null;
        $states = [];
        foreach (\is_array($teams) ? $teams : [] as $team) {
            if (!\is_array($team) || !\is_string($team['id'] ?? null) || !\is_string($team['state'] ?? null)) {
                continue;
            }
            if ('CONNECTABLE' === $team['state']) {
                return $team['id'];
            }
            $states[] = \in_array($team['state'], self::TEAM_STATES, true) ? $team['state'] : 'unknown';
        }

        throw new \RuntimeException(\sprintf('The user has no team to connect. The states of the teams: %s.', [] === $states ? 'none' : implode(', ', $states)));
    }

    /**
     * Follows the redirects of the authorization server to the first one that carries the parameter.
     *
     * @return array{url: string, value: string} the redirect, and the value of its parameter
     */
    private function redirectWith(string $url, string $parameter): array
    {
        for ($count = 0; $count < self::MAX_REDIRECTS; ++$count) {
            if (!str_starts_with($url, $this->authUrl.'/')) {
                throw new \RuntimeException(\sprintf('A redirect left the authorization server without "%s".', $parameter));
            }
            $response = $this->send('GET', $url);
            $location = $response['location'];
            if (null === $location) {
                throw new \RuntimeException(\sprintf('The authorization server returned status %d in place of a redirect with "%s".', $response['status'], $parameter));
            }
            parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
            $value = $query[$parameter] ?? null;
            if (\is_string($value)) {
                return ['url' => $location, 'value' => $value];
            }
            if (\is_string($query['error'] ?? null)) {
                throw new \RuntimeException(\sprintf('The authorization server refused the request: %s.', 1 === preg_match('/^[a-z_]{1,40}$/', $query['error']) ? $query['error'] : 'unknown'));
            }
            $url = $location;
        }

        throw new \RuntimeException(\sprintf('No redirect carries "%s".', $parameter));
    }

    /**
     * @param non-empty-string           $method
     * @param array<string, string>|null $body
     *
     * @return array<mixed>
     */
    private function json(string $step, string $method, string $url, #[\SensitiveParameter] ?string $token = null, #[\SensitiveParameter] ?array $body = null, int $expectedStatus = 200): array
    {
        $response = $this->send($method, $url, $token, $body);
        if ($expectedStatus !== $response['status']) {
            throw new \RuntimeException(\sprintf('The request to %s returned status %d.', $step, $response['status']));
        }
        $decoded = json_decode($response['body'], true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('The request to %s returned no JSON object.', $step));
        }

        return $decoded;
    }

    /**
     * @param non-empty-string           $method
     * @param array<string, string>|null $body
     *
     * @return array{status: int, location: string|null, body: string}
     */
    private function send(string $method, string $url, #[\SensitiveParameter] ?string $token = null, #[\SensitiveParameter] ?array $body = null): array
    {
        if ('' === $url) {
            throw new \RuntimeException('A request has no URL.');
        }
        $headers = ['Accept: application/json'];
        if (null !== $token) {
            $headers[] = 'Authorization: Bearer '.$token;
        }
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_reset($this->curl);
        curl_setopt_array($this->curl, [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_CONNECTTIMEOUT => 10,
            \CURLOPT_TIMEOUT => 30,
            // Keeps the cookies of the session and of the CSRF checks in memory, across the requests of the handle.
            \CURLOPT_COOKIEFILE => '',
        ] + (null === $body ? [] : [\CURLOPT_POSTFIELDS => json_encode($body, \JSON_THROW_ON_ERROR)]));

        $responseBody = curl_exec($this->curl);
        if (!\is_string($responseBody)) {
            throw new \RuntimeException(\sprintf('A request failed in transit (curl error %d).', curl_errno($this->curl)));
        }
        $location = curl_getinfo($this->curl, \CURLINFO_REDIRECT_URL);

        return [
            'status' => curl_getinfo($this->curl, \CURLINFO_RESPONSE_CODE),
            'location' => \is_string($location) && '' !== $location ? $location : null,
            'body' => $responseBody,
        ];
    }
}
