<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Parser\SourceFiles as SourceFilesInterface;

use function file_get_contents;
use function glob;

final class SourceFiles implements SourceFilesInterface
{
    #[\Override]
    public function matching(string $pattern): array
    {
        $paths = glob($pattern);
        return $paths === false ? [] : $paths;
    }

    #[\Override]
    public function read(string $path): ?string
    {
        $source = @file_get_contents($path);
        return $source === false ? null : $source;
    }
}
