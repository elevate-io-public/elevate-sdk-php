<?php

declare(strict_types=1);

namespace Elevate\Api;

use Elevate\Api\Operations\Auth;
use Elevate\Api\Operations\Media;
use Elevate\Api\Operations\Projects;
use Elevate\Api\Operations\Teams;

/**
 * The operations of the elevate.io API, grouped as the API specification tags them.
 *
 * Every operation throws:
 * - ApiException when elevate.io refuses the request;
 * - ValidationException when the request is invalid;
 * - TokenRequestException when elevate.io refuses a refresh, and `isInvalidGrant()` means the connection is revoked;
 * - TransportException when the request or a refresh fails in transit;
 * - UnexpectedResponseException when the SDK cannot read the response.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
abstract class Operations
{
    /**
     * The connected team.
     */
    public readonly Teams $teams;

    /**
     * The projects in the connected team.
     */
    public readonly Projects $projects;

    /**
     * Media ingest into the connected team.
     */
    public readonly Media $media;

    /**
     * The integrator's connection.
     */
    public readonly Auth $auth;

    protected function __construct(Transport $transport)
    {
        $this->teams = new Teams($transport);
        $this->projects = new Projects($transport);
        $this->media = new Media($transport);
        $this->auth = new Auth($transport);
    }
}
