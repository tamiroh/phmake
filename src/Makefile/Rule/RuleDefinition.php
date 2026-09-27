<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Rule;

/**
 * One rule as written in a makefile, before it is merged into the rule database.
 */
final readonly class RuleDefinition
{
    /**
     * @param list<string> $targetNames
     */
    public function __construct(
        public array $targetNames,
        public Prerequisites $prerequisites,
        public ?Recipe $recipe = null,
        public bool $doubleColon = false,
        public bool $grouped = false,
        public ?string $targetPattern = null,
        public ?string $source = null,
    ) {}
}
