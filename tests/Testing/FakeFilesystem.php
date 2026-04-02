<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Tamiroh\Phmake\Makefile\Filesystem;

final class FakeFilesystem implements Filesystem
{
    /**
     * @param array<string, bool> $exists
     * @param array<string, int> $lastModified
     */
    public function __construct(
        private readonly array $exists,
        private readonly array $lastModified,
    ) {}

    public function exists(string $path): bool
    {
        return $this->exists[$path] ?? false;
    }

    public function lastModified(string $path): ?int
    {
        return $this->lastModified[$path] ?? null;
    }
}
