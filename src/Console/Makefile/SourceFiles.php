<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Makefile;

use Override;
use Tamiroh\Phmake\Console\Filesystem\Filesystem;
use Tamiroh\Phmake\Console\Process\CapturedProcess;
use Tamiroh\Phmake\Parser\SourceFiles as SourceFilesInterface;
use Tamiroh\Phmake\Parser\SourceText;

use function clearstatcache;
use function error_get_last;
use function file_get_contents;
use function filemtime;
use function is_dir;
use function preg_replace;
use function trim;

use const PHP_OS_FAMILY;

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
        return new Filesystem()->matching($pattern);
    }

    #[Override]
    public function read(string $path): SourceText
    {
        $modifiedAt = $this->modifiedAt($path);
        if (is_dir($path)) {
            return new SourceText(null, 'Is a directory', $modifiedAt);
        }
        $source = @file_get_contents($path);
        return $source === false
            ? new SourceText(
                null,
                preg_replace('/^.*Failed to open stream: /', '', error_get_last()['message'] ?? 'I/O error'),
                $modifiedAt,
            )
            : new SourceText($source, modifiedAt: $modifiedAt);
    }

    /**
     * Preserve subsecond makefile timestamps where GNU or BSD stat is available.
     */
    private function modifiedAt(string $path): ?string
    {
        clearstatcache(true, $path);
        $seconds = @filemtime($path);
        if ($seconds === false) {
            return null;
        }
        $stat = CapturedProcess::run(
            PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD'
                ? ['stat', '-L', '-f', '%Fm', '--', $path]
                : ['stat', '-L', '--format=%y', '--', $path],
        );
        return $stat->status === 0 ? trim($stat->output) : (string) $seconds;
    }
}
