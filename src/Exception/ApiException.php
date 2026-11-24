<?php

declare(strict_types=1);

namespace Elevate\Exception;

/**
 * The elevate.io API refused the request.
 */
final class ApiException extends \RuntimeException implements ElevateException
{
    /**
     * @param string      $errorCode  the error code of the response, such as `INTEGRATOR_NOT_GRANTED`
     * @param string|null $detail     a description for the integrator's logs, not for the user
     * @param string|null $actionUrl  the elevate.io page where the user resolves the error; show the link to the user
     * @param int         $statusCode the HTTP status of the response
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $detail,
        public readonly ?string $actionUrl,
        public readonly int $statusCode,
    ) {
        parent::__construct(
            null === $detail
                ? \sprintf('The API returned "%s" with status %d.', $errorCode, $statusCode)
                : \sprintf('The API returned "%s" with status %d: %s', $errorCode, $statusCode, $detail),
        );
    }

    /**
     * elevate.io refused the access token. `ApiClient` has already refreshed once for this call, so report it to
     * elevate.io.
     */
    public function isInvalidToken(): bool
    {
        return 401 === $this->statusCode;
    }

    /**
     * The connection does not cover the resource that the request names, or the user revoked it. Keep the refresh
     * token until a refresh fails with `TokenRequestException::isInvalidGrant()`.
     */
    public function isNotGranted(): bool
    {
        return 'INTEGRATOR_NOT_GRANTED' === $this->errorCode;
    }

    /**
     * The team's plan does not include the integration. The connection works again on an eligible plan, so keep the
     * refresh token.
     */
    public function isPlanNotEligible(): bool
    {
        return 'PLAN_NOT_ELIGIBLE' === $this->errorCode;
    }
}
