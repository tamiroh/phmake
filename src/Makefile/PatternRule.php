<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final readonly class PatternRule
{
    /** @param list<string> $names */
    public function __construct(
        public array $names,
        public BuildRule $rule,
    ) {}
}
