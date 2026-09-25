<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

interface SourceFiles
{
    public function isDirectory(string $path): bool;

    /**
     * @return list<string>
     */
    public function matching(string $pattern): array;

    public function read(string $path): SourceText;
}
