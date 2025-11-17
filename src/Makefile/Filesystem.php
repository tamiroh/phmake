<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

interface Filesystem
{
    public function exists(string $path): bool;

    public function lastModified(string $path): ?int;
}
