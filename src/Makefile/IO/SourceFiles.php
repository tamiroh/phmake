<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

/**
 * Find and read makefiles, including default names and include patterns, and detect
 * whether remaking them changed their contents' timestamps and requires a restart.
 */
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
     * Precise modification times, null for missing files; equal strings mean an unchanged file.
     *
     * @param list<string> $paths
     *
     * @return array<string, ?string>
     */
    public function modifiedTimes(array $paths): array;

    /**
     * The text's modification time only tells whether the file existed; compare times from modifiedTimes().
     */
    public function read(string $path): SourceText;
}
