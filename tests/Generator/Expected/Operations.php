<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected;

use Elevate\Api\Transport;
use Elevate\Tests\Generator\Expected\Operations\Books;
use Elevate\Tests\Generator\Expected\Operations\Shelves;

/**
 * The operations of the elevate.io API, grouped as the API specification tags them.
 *
 * Every operation throws:
 * - ApiException when elevate.io refuses the request;
 * - ValidationException when the request is invalid;
 * - TokenRequestException when elevate.io refuses a refresh, and `isInvalidGrant()` means the connection is revoked;
 * - TransportException when the request or a refresh fails in transit, or elevate.io fails with a status of 500 or more;
 * - UnexpectedResponseException when the SDK cannot read the response.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
abstract class Operations
{
    /**
     * The books on a shelf.
     * A shelf holds any number.
     */
    public readonly Books $books;

    public readonly Shelves $shelves;

    protected function __construct(Transport $transport)
    {
        $this->books = new Books($transport);
        $this->shelves = new Shelves($transport);
    }
}
