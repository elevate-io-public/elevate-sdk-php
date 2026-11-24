<?php

declare(strict_types=1);

namespace Elevate\Api;

use Elevate\Auth\SystemClock;
use Elevate\Auth\Tokens;
use Elevate\Clock;
use Elevate\Config;
use Elevate\Http\CurlHttpClient;
use Elevate\Http\HttpClient;

/**
 * Calls the elevate.io API for one connection, and refreshes that connection's access token when it has to.
 *
 * The Operations class holds the operations of the API, grouped as in `$apiClient->teams->getTeam($teamId)`.
 */
final class ApiClient extends Operations
{
    private readonly Transport $transport;

    /**
     * @param Tokens                 $tokens    the stored tokens of the connection; an access token that is empty or
     *                                          outside visible ASCII counts as expired
     * @param \Closure(Tokens): void $onRefresh stores the tokens of each refresh in place of the previous ones; the
     *                                          previous refresh token stops working after 60 seconds
     */
    public function __construct(
        private readonly Config $config,
        #[\SensitiveParameter]
        Tokens $tokens,
        \Closure $onRefresh,
        ?HttpClient $httpClient = null,
        ?Clock $clock = null,
    ) {
        $this->transport = new Transport(
            $config,
            $tokens,
            $onRefresh,
            $httpClient ?? new CurlHttpClient(),
            $clock ?? new SystemClock(),
        );
        parent::__construct($this->transport);
    }

    /**
     * Returns the tokens that the client holds now: the constructor's, or those of the latest refresh.
     */
    public function tokens(): Tokens
    {
        return $this->transport->tokens();
    }

    /**
     * Omits the tokens: var_dump and print_r print what this method returns.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['apiUrl' => $this->config->apiUrl];
    }
}
