<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

interface SourceFiles
{
    public function isDirectory(string $path): bool;

    /**
     * Whether a regular file exists with exactly this name, even on case-insensitive filesystems.
     */
    public function isFile(string $path): bool;

    /**
     * @return list<string>
     */
    public function matching(string $pattern): array;

    /**
     * Subsecond modification times where available, or null for missing files.
     *
     * @param list<string> $paths
     *
     * @return array<string, ?string>
     */
    public function modifiedTimes(array $paths): array;

    /**
     * The modification time has one-second resolution; compare regenerated makefiles with modifiedTimes().
     */
    public function read(string $path): SourceText;

    /**
     * Reads with the same modification times as modifiedTimes().
     *
     * @param list<string> $paths
     *
     * @return iterable<string, SourceText>
     */
    public function readMany(array $paths): iterable;
}
