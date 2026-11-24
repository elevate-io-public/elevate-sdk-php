<?php

declare(strict_types=1);

namespace Elevate\Tests;

trait AssertsTraces
{
    private static function assertTraceOmits(\Throwable $exception, string ...$secrets): void
    {
        $strings = [];
        $arguments = array_column($exception->getTrace(), 'args');
        array_walk_recursive($arguments, static function (mixed $argument) use (&$strings): void {
            if (\is_string($argument)) {
                $strings[] = $argument;
            }
        });
        self::assertNotEmpty($arguments, 'The trace carries no arguments.');
        foreach ($secrets as $secret) {
            foreach ($strings as $string) {
                self::assertStringNotContainsString($secret, $string);
            }
        }
    }
}
