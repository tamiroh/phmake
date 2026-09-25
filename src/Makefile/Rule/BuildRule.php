<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Rule;

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
