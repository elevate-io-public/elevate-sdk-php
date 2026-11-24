<?php

declare(strict_types=1);

namespace Elevate\Api\Operations;

use Elevate\Api\Transport;
use Elevate\Exception\ApiException;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;

/**
 * The integrator's connection.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Auth
{
    public function __construct(private Transport $transport)
    {
    }

    /**
     * Revoke the grant that the access token names, whatever resource it covers. This disconnects the integrator.
     * Every access token and refresh token issued under the grant stops working.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function revokeGrant(): void
    {
        $this->transport->call('POST', '/v1/auth/revoke-grant', [], [], null, null);
    }
}
