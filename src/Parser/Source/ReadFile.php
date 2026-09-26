<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Source;

/**
 * One makefile read, including missing files which may be generated later.
 */
final readonly class ReadFile
{
    public function __construct(
        public string $path,
        public ?string $text,
        public ?string $modifiedAt,
        public bool $optional = false,
        public bool $defaultGoal = true,
        public ?string $source = null,
        public bool $rebuild = true,
        public ?string $error = null,
        public ?string $displayPath = null,
    ) {}
}
