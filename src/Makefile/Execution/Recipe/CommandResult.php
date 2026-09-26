<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Recipe;

final readonly class CommandResult
{
    public function __construct(
        public bool $active = false,
        public bool $simulated = false,
        public int $exitCode = 0,
        public bool $needsUpdate = false,
    ) {}
}
