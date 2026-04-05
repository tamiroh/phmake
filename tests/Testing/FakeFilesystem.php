<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use DateTimeImmutable;
use Tamiroh\Phmake\Makefile\Filesystem;

final class FakeFilesystem implements Filesystem
{
    /**
     * @param array<string, array{modifiedAt: DateTimeImmutable}> $files
     */
    public function __construct(
        private readonly array $files,
    ) {}

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function lastModified(string $path): ?int
    {
        if (!array_key_exists($path, $this->files)) {
            return null;
        }

        return $this->files[$path]['modifiedAt']->getTimestamp();
    }
}
