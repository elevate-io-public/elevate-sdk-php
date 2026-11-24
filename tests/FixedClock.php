<?php

declare(strict_types=1);

namespace Elevate\Tests;

use Elevate\Clock;

final readonly class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public static function at(int $timestamp): self
    {
        return new self(new \DateTimeImmutable('@'.$timestamp));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
