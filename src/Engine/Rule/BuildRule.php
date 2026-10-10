<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Rule;

/**
 * A rule's prerequisites and recipe, with its stem when selected for a target.
 * Target updates and recipe execution are handled by Execution/Build.
 */
final readonly class BuildRule
{
    /**
     * @param list<string> $group
     */
    public function __construct(
        public Prerequisites $prerequisites = new Prerequisites(),
        public ?Recipe $recipe = null,
        public bool $doubleColon = false,
        public string $stem = '',
        public array $group = [],
        public ?string $firstPrerequisite = null,
        public bool $implicit = false,
    ) {}
}
