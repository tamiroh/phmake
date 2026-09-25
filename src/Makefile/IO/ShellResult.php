<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

final readonly class ShellResult
{
    public function __construct(
        public string $output,
        public int $status,
    ) {}
}
