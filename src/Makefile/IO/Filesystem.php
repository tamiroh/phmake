<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

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

    public function workingDirectory(): string;

    /**
     * @throws MakefileErrorException
     */
    public function write(string $path, string $text, bool $append): void;
}
