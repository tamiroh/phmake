<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * File facts and operations used by make: timestamps, directory search, wildcard / realpath,
 * the file function, touching targets, and deleting intermediate or failed targets.
 * Archive members and timestamp overrides are resolved by the implementation.
 */
interface Filesystem
{
    public function exists(string $path): bool;

    public function lastModified(string $path): ?int;

    /**
     * @return list<string>
     */
    public function matching(string $pattern): array;

    /**
     * @throws MakefileErrorException
     */
    public function read(string $path): ?string;

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
