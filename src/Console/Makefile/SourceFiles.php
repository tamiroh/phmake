<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Makefile;

use Override;
use Tamiroh\Phmake\Console\Filesystem\Filesystem;
use Tamiroh\Phmake\Console\Process\CapturedProcess;
use Tamiroh\Phmake\Makefile\IO\SourceFiles as SourceFilesInterface;
use Tamiroh\Phmake\Makefile\IO\SourceText;

use function array_chunk;
use function basename;
use function clearstatcache;
use function count;
use function dirname;
use function error_get_last;
use function explode;
use function file_get_contents;
use function filemtime;
use function in_array;
use function is_dir;
use function is_file;
use function preg_replace;
use function rtrim;
use function scandir;
use function trim;

use const PHP_OS_FAMILY;

final class SourceFiles implements SourceFilesInterface
{
    private static function seconds(string $path): ?string
    {
        clearstatcache(true, $path);
        $seconds = @filemtime($path);
        return $seconds === false ? null : (string) $seconds;
    }

    /**
     * @param list<string> $paths
     *
     * @return non-empty-list<string>
     */
    private static function statCommand(array $paths): array
    {
        return (
            PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD'
                ? ['stat', '-L', '-f', '%Fm', '--', ...$paths]
                : ['stat', '-L', '--format=%y', '--', ...$paths]
        );
    }

    #[Override]
    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    #[Override]
    public function isFile(string $path): bool
    {
        $entries = scandir(dirname($path));
        return $entries !== false && in_array(basename($path), $entries, true) && is_file($path);
    }

    #[Override]
    public function matching(string $pattern): array
    {
        return new Filesystem()->matching($pattern);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, ?string>
     */
    #[Override]
    public function modifiedTimes(array $paths): array
    {
        $times = [];
        $existing = [];
        foreach ($paths as $path) {
            $times[$path] = self::seconds($path);
            if ($times[$path] !== null) {
                $existing[] = $path;
            }
        }
        // Bound command size; a failed batch falls back to individual checks.
        foreach (array_chunk($existing, 32) as $batch) {
            $stat = CapturedProcess::run(self::statCommand($batch));
            $lines = explode("\n", rtrim($stat->output, "\n"));
            foreach ($batch as $index => $path) {
                $times[$path] = $stat->status === 0 && count($lines) === count($batch) && isset($lines[$index])
                    ? trim($lines[$index])
                    : $this->modifiedAt($path);
            }
        }
        return $times;
    }

    #[Override]
    public function read(string $path): SourceText
    {
        return $this->readWithTime($path, self::seconds($path));
    }

    /**
     * @param list<string> $paths
     *
     * @return iterable<string, SourceText>
     */
    #[Override]
    public function readMany(array $paths): iterable
    {
        foreach ($this->modifiedTimes($paths) as $path => $modifiedAt) {
            yield $path => $this->readWithTime($path, $modifiedAt);
        }
    }

    /**
     * Preserve subsecond makefile timestamps where GNU or BSD stat is available.
     */
    private function modifiedAt(string $path): ?string
    {
        $seconds = self::seconds($path);
        if ($seconds === null) {
            return null;
        }
        $stat = CapturedProcess::run(self::statCommand([$path]));
        return $stat->status === 0 ? trim($stat->output) : $seconds;
    }

    private function readWithTime(string $path, ?string $modifiedAt): SourceText
    {
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
}
