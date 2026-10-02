<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Filesystem;

use Tamiroh\Phmake\Console\Process\CapturedProcess;

use function array_chunk;
use function clearstatcache;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function filemtime;
use function is_link;
use function lstat;
use function max;
use function readlink;
use function rtrim;
use function str_starts_with;
use function trim;

use const PHP_OS_FAMILY;

/**
 * File modification times for target updates and precise makefile restart comparisons.
 */
final class FileTimes
{
    private const int BATCH_SIZE = 1024;

    public static function modified(string $path, bool $links = false): ?int
    {
        clearstatcache(true, $path);
        $time = @filemtime($path);
        if (!$links) {
            return $time === false ? null : $time;
        }
        for ($depth = 0; $depth < 40 && is_link($path); $depth++) {
            $stat = @lstat($path);
            if ($stat !== false) {
                $time = $time === false ? $stat['mtime'] : max($time, $stat['mtime']);
            }
            $link = @readlink($path);
            if ($link === false) {
                break;
            }
            $path = str_starts_with($link, '/') ? $link : dirname($path) . '/' . $link;
            clearstatcache(true, $path);
        }
        return $time === false ? null : $time;
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, ?string>
     */
    public static function modifiedTimes(array $paths): array
    {
        $times = [];
        $existing = [];
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $times[$path] = null;
            // One missing path would fail the whole stat command.
            if (file_exists($path)) {
                $existing[] = $path;
            }
        }
        // A failed batch falls back to one command per path.
        foreach (array_chunk($existing, self::BATCH_SIZE) as $batch) {
            $stat = CapturedProcess::run(self::statCommand($batch));
            $lines = explode("\n", rtrim($stat['output'], "\n"));
            foreach ($batch as $index => $path) {
                $times[$path] = $stat['status'] === 0 && count($lines) === count($batch) && isset($lines[$index])
                    ? trim($lines[$index])
                    : self::modifiedAt($path);
            }
        }
        return $times;
    }

    /**
     * Preserve subsecond makefile timestamps where GNU or BSD stat is available.
     */
    private static function modifiedAt(string $path): ?string
    {
        clearstatcache(true, $path);
        $seconds = @filemtime($path);
        if ($seconds === false) {
            return null;
        }
        $stat = CapturedProcess::run(self::statCommand([$path]));
        return $stat['status'] === 0 ? trim($stat['output']) : (string) $seconds;
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
}
