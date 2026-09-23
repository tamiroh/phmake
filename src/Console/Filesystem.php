<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Filesystem as FilesystemInterface;

use function clearstatcache;
use function file_exists;
use function filemtime;

final class Filesystem implements FilesystemInterface
{
    #[\Override]
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    #[\Override]
    public function lastModified(string $path): ?int
    {
        clearstatcache(true, $path);
        $result = @filemtime($path);

        return $result === false ? null : $result;
    }
}
