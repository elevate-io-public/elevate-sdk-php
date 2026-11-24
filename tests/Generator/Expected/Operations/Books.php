<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Operations;

use Elevate\Api\Transport;
use Elevate\Exception\ApiException;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;
use Elevate\Tests\Generator\Expected\Model\Book;
use Elevate\Tests\Generator\Expected\Model\BookList;
use Elevate\Tests\Generator\Expected\Model\Condition;
use Elevate\Tests\Generator\Expected\Model\Genre;
use Elevate\Tests\Generator\Expected\Model\NewBook;

/**
 * The books on a shelf.
 * A shelf holds any number.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Books
{
    public function __construct(private Transport $transport)
    {
    }

    /**
     * List the books on a shelf.
     * The list is in shelf order.
     * It needs the `books:read` and `shelves:read` scopes.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function listBooks(string $shelfId, Genre $genre, ?Condition $condition = null, ?int $limit = null, ?bool $signed = null, ?string $after = null): BookList
    {
        return $this->transport->call('GET', '/v1/shelves/{shelfId}/books', ['shelfId' => $shelfId], ['genre' => $genre->value, 'condition' => $condition?->value, 'limit' => $limit, 'signed' => $signed, 'after' => $after], null, BookList::class);
    }

    /**
     * Add a book to a shelf.
     * It needs the `books:write` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function addBook(string $shelfId, NewBook $body): Book
    {
        return $this->transport->call('POST', '/v1/shelves/{shelfId}/books', ['shelfId' => $shelfId], [], $body, Book::class);
    }
}
