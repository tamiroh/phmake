<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Filesystem as FilesystemInterface;

final class Filesystem implements FilesystemInterface
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function lastModified(string $path): ?int
    {
        $result = @filemtime($path);

        return $result === false ? null : $result;
    }
}
