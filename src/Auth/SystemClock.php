<?php

declare(strict_types=1);

namespace Elevate\Auth;

use Elevate\Clock;

/**
 * @internal
 */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
