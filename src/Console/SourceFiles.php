<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Override;
use Tamiroh\Phmake\Parser\SourceFiles as SourceFilesInterface;
use Tamiroh\Phmake\Parser\SourceText;

use function error_get_last;
use function file_get_contents;
use function glob;
use function is_dir;
use function preg_replace;

final class SourceFiles implements SourceFilesInterface
{
    #[Override]
    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    #[Override]
    public function matching(string $pattern): array
    {
        $paths = glob($pattern);
        return $paths === false ? [] : $paths;
    }

    #[Override]
    public function read(string $path): SourceText
    {
        if (is_dir($path)) {
            return new SourceText(null, 'Is a directory');
        }
        $source = @file_get_contents($path);
        return $source === false
            ? new SourceText(null, preg_replace(
                '/^.*Failed to open stream: /',
                '',
                error_get_last()['message'] ?? 'I/O error',
            ))
            : new SourceText($source);
    }
}
