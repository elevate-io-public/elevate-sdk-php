<?php

declare(strict_types=1);

namespace Elevate;

/**
 * Tells the time. A test passes its own to fix the time.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
