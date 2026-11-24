<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator\Expected\Model;

/**
 * The genre of a book.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
enum Genre: string
{
    case Fiction = 'FICTION';
    case NonFiction = 'NON_FICTION';
}
