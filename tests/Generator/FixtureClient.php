<?php

declare(strict_types=1);

namespace Elevate\Tests\Generator;

use Elevate\Api\Transport;
use Elevate\Tests\Generator\Expected\Operations;

/**
 * Sends the generated fixture operations through the transport.
 */
final class FixtureClient extends Operations
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
    }
}
