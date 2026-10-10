<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\IO;

use Tamiroh\Phmake\Engine\MakefileErrorException;

/**
 * File facts and operations used by make: timestamps, directory search, wildcard / realpath,
 * the file function, touching targets, and deleting intermediate or failed targets.
 * Archive members and timestamp overrides are resolved by the implementation.
 */
interface Filesystem
{
    public function exists(string $path): bool;

    public function isDirectory(string $path): bool;

    /**
     * Whether a regular file exists with exactly this name, even on case-insensitive filesystems.
     */
    public function isFile(string $path): bool;

    public function lastModified(string $path): ?int;

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
     * Return file contents or a read error without throwing on an I/O failure.
     * The modification time only indicates existence; compare times with modifiedTimes().
     *
     * @return array{text: ?string, error: ?string, modifiedAt: ?string}
     */
    public function read(string $path): array;

    public function realpath(string $path): ?string;

    public function remove(string $path): bool;

    /**
     * @throws MakefileErrorException
     */
    public function touch(string $path): void;

    public function workingDirectory(): string;

    /**
     * @throws MakefileErrorException
     */
    public function write(string $path, string $text, bool $append): void;
}
