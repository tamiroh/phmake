<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final readonly class Target
{
    /**
     * @param list<BuildRule> $rules
     */
    public function __construct(
        public string $name,
        public array $rules = [],
        public bool $isPhony = false,
    ) {}
}
