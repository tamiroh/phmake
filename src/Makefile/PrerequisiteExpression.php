<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

/** Preserve each declaration's first expansion and static stem for later secondary expansion. */
final readonly class PrerequisiteExpression
{
    public function __construct(
        public string $text,
        public ?string $stem = null,
        public bool $hasRecipe = false,
        public ?string $source = null,
        public bool $secondary = false,
        public ?Prerequisites $initial = null,
    ) {}
}
