<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

/**
 * Expanded recipe text, retaining prefixes and recursion from the original expression.
 */
final readonly class ExpandedCommand
{
    public function __construct(
        public string $expression,
        public string $prefix = '',
        public bool $recursive = false,
        public ?string $source = null,
    ) {}
}
