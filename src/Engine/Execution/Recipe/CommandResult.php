<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Recipe;

/**
 * @internal
 */
final readonly class CommandResult
{
    /**
     * $started distinguishes recipe processing from a file-local touch update.
     */
    public function __construct(
        public bool $active = false,
        public bool $simulated = false,
        public int $exitCode = 0,
        public bool $needsUpdate = false,
        public bool $started = false,
        public bool $recursive = false,
    ) {}
}
