<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Operations;

use Elevate\Api\Transport;
use Elevate\Exception\ApiException;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;

/**
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Shelves
{
    public function __construct(private Transport $transport)
    {
    }

    /**
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function removeBook(string $shelfId, string $bookId): void
    {
        $this->transport->call('DELETE', '/v1/shelves/{shelfId}/books/{bookId}', ['shelfId' => $shelfId, 'bookId' => $bookId], [], null, null);
    }
}
