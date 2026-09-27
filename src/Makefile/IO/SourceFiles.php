<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

interface SourceFiles
{
    public function isDirectory(string $path): bool;

    /**
     * @return list<string>
     */
    public function matching(string $pattern): array;

    public function read(string $path): SourceText;

    /**
     * @param list<string> $paths
     *
     * @return iterable<string, SourceText>
     */
    public function readMany(array $paths): iterable;
}
