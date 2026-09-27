<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Makefile;

use Override;
use Tamiroh\Phmake\Console\Filesystem\Filesystem;
use Tamiroh\Phmake\Console\Process\CapturedProcess;
use Tamiroh\Phmake\Parser\Source\SourceFiles as SourceFilesInterface;
use Tamiroh\Phmake\Parser\Source\SourceText;

use function array_chunk;
use function clearstatcache;
use function count;
use function error_get_last;
use function explode;
use function file_get_contents;
use function filemtime;
use function is_dir;
use function preg_replace;
use function rtrim;
use function trim;

use const PHP_OS_FAMILY;

final class SourceFiles implements SourceFilesInterface
{
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
    public function matching(string $pattern): array
    {
        return new Filesystem()->matching($pattern);
    }

    #[Override]
    public function read(string $path): SourceText
    {
        return $this->readWithTime($path, $this->modifiedAt($path));
    }

    /**
     * @param list<string> $paths
     *
     * @return iterable<string, SourceText>
     */
    #[Override]
    public function readMany(array $paths): iterable
    {
        // Bound command size; a failed batch falls back to individual reads.
        foreach (array_chunk($paths, 32) as $batch) {
            foreach ($batch as $path) {
                clearstatcache(true, $path);
            }
            $stat = CapturedProcess::run(self::statCommand($batch));
            $times = explode("\n", rtrim($stat->output, "\n"));
            foreach ($batch as $index => $path) {
                yield $path => $stat->status === 0 && count($times) === count($batch) && isset($times[$index])
                    ? $this->readWithTime($path, trim($times[$index]))
                    : $this->read($path);
            }
        }
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
        $stat = CapturedProcess::run(self::statCommand([$path]));
        return $stat->status === 0 ? trim($stat->output) : (string) $seconds;
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
