<?php

declare(strict_types=1);

namespace Elevate\Generator;

final readonly class Type
{
    /**
     * @param string $kind the Decode method that reads the value: string, int, float, bool, enum, model, list or map
     * @param string $php  the native type
     * @param string $doc  the PHPDoc type
     */
    public function __construct(
        public string $kind,
        public string $php,
        public string $doc,
        public ?self $inner = null,
    ) {
    }
}
