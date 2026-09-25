<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

final readonly class Variable
{
    public function __construct(
        public string $name,
        public string $expression,
        public bool $recursive = true,
        public string $origin = 'file',
        public ?string $source = null,
        public bool $private = false,
        public ?bool $export = null,
        public bool $append = false,
        public bool $conditional = false,
    ) {}
}
