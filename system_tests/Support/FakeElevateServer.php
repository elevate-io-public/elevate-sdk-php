<?php

declare(strict_types=1);

namespace Elevate\SystemTests\Support;

use Elevate\Clock;
use Elevate\Config;

/**
 * Runs FakeElevate in a server process, and acts on it from the test: as the user, as elevate.io and as the clock.
 */
final class FakeElevateServer implements Clock
{
    private static ?self $running = null;
    private static ?\RuntimeException $failure = null;
    private int $logOffset = 0;

    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        public readonly string $url,
        private readonly string $stateFile,
        private readonly string $logFile,
    ) {
    }

    /**
     * Returns the one server of the test run, and starts it at the first call. It does not try a failed start again:
     * each test would wait up to five seconds for it.
     */
    public static function running(): self
    {
        if (null !== self::$failure) {
            throw self::$failure;
        }
        try {
            return self::$running ??= self::start();
        } catch (\RuntimeException $failure) {
            throw self::$failure = $failure;
        }
    }

    /**
     * Forgets everything, and expects the client of the Config from now on.
     *
     * @param string $publicKey the PEM-encoded public key of the client
     * @param int    $now       the time of the fake, as a Unix timestamp
     */
    public function reset(Config $config, string $publicKey, int $now, int $accessTokenLifetime): void
    {
        (new State($this->url, $config->clientId, $config->keyId, $publicKey, $config->signingAlgorithm, $now, $accessTokenLifetime))->save($this->stateFile);
        $this->logOffset = \strlen($this->log());
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$this->state()->now);
    }

    public function advance(int $seconds): void
    {
        $this->change(static function (State $state) use ($seconds): void {
            $state->now += $seconds;
        });
    }

    /**
     * Acts as the user who opens the authorization URL and connects a team.
     *
     * @return string the redirect to the integrator, with the `code` and `state` query parameters
     */
    public function approve(string $authorizationUrl, string $teamId): string
    {
        return $this->change(static function (State $state) use ($authorizationUrl, $teamId): string {
            parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);
            $expected = [
                'response_type' => 'code',
                'client_id' => $state->clientId,
                'audience' => $state->url,
                'code_challenge_method' => 'S256',
            ];
            foreach ($expected as $name => $value) {
                if (($query[$name] ?? null) !== $value) {
                    throw new ContractViolation(\sprintf('The authorization request has another "%s".', $name));
                }
            }
            if (!str_starts_with($authorizationUrl, $state->url.'/oauth2/auth?')) {
                throw new ContractViolation('The authorization request addresses another URL.');
            }
            $stateParameter = $query['state'] ?? null;
            $challenge = $query['code_challenge'] ?? null;
            $redirectUri = $query['redirect_uri'] ?? null;
            $scope = $query['scope'] ?? null;
            if (!\is_string($stateParameter) || !\is_string($challenge) || !\is_string($redirectUri) || !\is_string($scope) || '' === $stateParameter || '' === $challenge) {
                throw new ContractViolation('The authorization request lacks the state, the code challenge, the redirect URI or the scope.');
            }
            $scopes = explode(' ', $scope);
            if (!\in_array('select:team', $scopes, true)) {
                throw new ContractViolation('The authorization request does not ask the user to select a team.');
            }

            $code = 'code-'.bin2hex(random_bytes(16));
            $state->codes[$code] = ['challenge' => $challenge, 'redirectUri' => $redirectUri, 'scopes' => $scopes, 'teamId' => $teamId, 'used' => false];

            return $redirectUri.'?'.http_build_query(['code' => $code, 'state' => $stateParameter]);
        });
    }

    /**
     * Acts as the user who disconnects the integrator from the team in elevate.io.
     */
    public function revokeConnection(string $teamId): void
    {
        $this->change(static function (State $state) use ($teamId): void {
            foreach ($state->grants as $grantId => $grant) {
                if ($grant['teamId'] === $teamId) {
                    $state->grants[$grantId]['revoked'] = true;
                }
            }
        });
    }

    /**
     * Ends every access token before its expiry. The refresh tokens keep working.
     */
    public function endAccessTokens(): void
    {
        $this->change(static function (State $state): void {
            $state->accessTokens = [];
        });
    }

    /**
     * Loses the response to the next request in transit: the fake handles the request, and the server closes the
     * connection before the whole response.
     */
    public function loseNextResponse(): void
    {
        $this->change(static function (State $state): void {
            ++$state->lostResponses;
        });
    }

    /**
     * @return list<array{method: string, uri: string, body: string}> every request, in the order of arrival
     */
    public function requests(): array
    {
        return $this->state()->requests;
    }

    /**
     * @return list<string> the id of the operation of each API request, in the order of arrival
     */
    public function operations(): array
    {
        return $this->state()->operations;
    }

    /**
     * @return list<string> the message of each ContractViolation and of each error of the fake
     */
    public function violations(): array
    {
        return $this->state()->violations;
    }

    /**
     * @return list<string> each line that the server process wrote to its log since the last reset, without the lines
     *                      about a connection or a served request
     */
    public function faults(): array
    {
        $lines = preg_split('/\R/', substr($this->log(), $this->logOffset), flags: \PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            false === $lines ? [] : $lines,
            static fn (string $line): bool => 1 !== preg_match('/^\[[^]]+\] \S+:\d+ (Accepted$|Closing$|Closed without sending a request|\[\d{3}\]: )/', $line),
        ));
    }

    private static function start(): self
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'elevate-fake-');
        $logFile = tempnam(sys_get_temp_dir(), 'elevate-fake-log-');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = false === $probe ? false : stream_socket_get_name($probe, false);
        if (false === $stateFile || false === $logFile || false === $probe || false === $address) {
            throw new \RuntimeException('The test server has no state file, no log file or no free port.');
        }
        fclose($probe);

        $process = proc_open(
            // Sends the PHP errors to the log: a displayed error starts the response body, and the router can then set
            // neither the status nor the headers.
            [\PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', $address, __DIR__.'/server.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', $logFile, 'a']],
            $pipes,
            null,
            ['ELEVATE_FAKE_STATE' => $stateFile] + getenv(),
        );
        if (false === $process) {
            throw new \RuntimeException('The test server did not start.');
        }
        $server = new self($process, 'http://'.$address, $stateFile, $logFile);
        register_shutdown_function($server->stop(...));

        [$host, $port] = explode(':', $address);
        for ($attempt = 0; $attempt < 100 && proc_get_status($process)['running']; ++$attempt) {
            $connection = @fsockopen($host, (int) $port);
            if (false !== $connection) {
                fclose($connection);

                return $server;
            }
            usleep(50_000);
        }
        throw new \RuntimeException(\sprintf("The test server did not accept a connection. Its log:\n%s", $server->log()));
    }

    private function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->stateFile);
        @unlink($this->logFile);
    }

    private function log(): string
    {
        $log = file_get_contents($this->logFile);
        if (false === $log) {
            throw new \RuntimeException(\sprintf('The log file %s of the test server is not readable.', $this->logFile));
        }

        return $log;
    }

    private function state(): State
    {
        return State::load($this->stateFile);
    }

    /**
     * @template T
     *
     * @param \Closure(State): T $change
     *
     * @return T
     */
    private function change(\Closure $change): mixed
    {
        $state = $this->state();
        $result = $change($state);
        $state->save($this->stateFile);

        return $result;
    }
}
