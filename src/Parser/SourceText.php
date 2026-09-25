<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

/**
 * Read failures remain available until the makefile regeneration phase.
 */
final readonly class SourceText
{
    public function __construct(
        public ?string $text,
        public ?string $error = null,
        public ?string $modifiedAt = null,
    ) {}
}
