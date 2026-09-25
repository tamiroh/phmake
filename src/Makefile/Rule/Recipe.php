<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Rule;

use Tamiroh\Phmake\Makefile\Execution\Command;

/**
 * One recipe shared by every target belonging to a grouped rule.
 */
final readonly class Recipe
{
    /**
     * @param list<Command> $commands
     */
    public function __construct(
        public array $commands,
        public ?string $source = null,
    ) {}
}
