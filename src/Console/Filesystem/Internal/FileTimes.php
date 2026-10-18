<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Filesystem\Internal;

use Tamiroh\Phmake\Console\Filesystem\FilesystemChanges;
use Tamiroh\Phmake\Console\Process\CapturedProcess;

use function array_chunk;
use function basename;
use function clearstatcache;
use function closedir;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function filemtime;
use function is_link;
use function is_resource;
use function lstat;
use function max;
use function opendir;
use function preg_match;
use function readdir;
use function readlink;
use function realpath;
use function rtrim;
use function stat;
use function str_starts_with;
use function strlen;
use function trim;

use const PHP_OS_FAMILY;

/**
 * File modification times for target updates and precise makefile restart comparisons.
 * PHP stat supplies whole seconds only. Prefetch sibling timestamps in bounded stat batches,
 * retaining nanoseconds until a command, write, restart, or recursive invocation invalidates them.
 * Metadata changes detected by PHP stat also invalidate an individual prefetched record.
 */
final class FileTimes
{
    private const int BATCH_SIZE = 1024;

    /** @var array<string, array{time: int, signature: list<int>}> */
    private static array $preciseTimes = [];

    /** @var array<string, true> */
    private static array $directories = [];

    private static int $version = -1;

    public static function modified(string $path, bool $links = false): ?int
    {
        clearstatcache(true, $path);
        $time = self::precise($path);
        if (!$links) {
            return $time;
        }
        for ($depth = 0; $depth < 40 && is_link($path); $depth++) {
            $linkTime = self::precise($path, false);
            if ($linkTime !== null) {
                $time = $time === null ? $linkTime : max($time, $linkTime);
            }
            $link = @readlink($path);
            if ($link === false) {
                break;
            }
            $path = str_starts_with($link, '/') ? $link : dirname($path) . '/' . $link;
            clearstatcache(true, $path);
        }
        return $time;
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
     * Return epoch nanoseconds, preserving the precision supplied by stat.
     */
    private static function precise(string $path, bool $follow = true): ?int
    {
        $stat = $follow ? @stat($path) : @lstat($path);
        if ($stat === false) {
            return null;
        }
        if (self::$version !== FilesystemChanges::version()) {
            self::$preciseTimes = [];
            self::$directories = [];
            self::$version = FilesystemChanges::version();
        }
        // lstat and reads concurrent with a recipe must observe the filesystem directly.
        if (!$follow || FilesystemChanges::active()) {
            return self::preciseBatch([$path], $follow)[$path]['time'] ?? ($stat['mtime'] * 1_000_000_000);
        }
        $directory = realpath(dirname($path));
        if ($directory === false) {
            return self::preciseBatch([$path])[$path]['time'] ?? ($stat['mtime'] * 1_000_000_000);
        }
        $key = $directory . '/' . basename($path);
        $signature = [$stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']];
        $cached = self::$preciseTimes[$key] ?? null;
        if ($cached === null && !isset(self::$directories[$directory])) {
            self::prefetch($directory, $key);
            $cached = self::$preciseTimes[$key] ?? null;
        }
        if ($cached !== null && $cached['signature'] === $signature) {
            return $cached['time'];
        }
        $current = self::preciseBatch([$key])[$key] ?? null;
        if ($current !== null) {
            self::$preciseTimes[$key] = $current;
            return $current['time'];
        }
        return $stat['mtime'] * 1_000_000_000;
    }

    /**
     * Numeric records keep arbitrary filenames, including newlines, out of the response protocol.
     * Reject a partial batch: a disappearing file must not shift another file's timestamp into its slot.
     *
     * @param non-empty-list<string> $paths
     *
     * @return array<string, array{time: int, signature: list<int>}>
     */
    private static function preciseBatch(array $paths, bool $follow = true): array
    {
        $bsd = PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD';
        $result = CapturedProcess::run([
            'stat',
            ...($follow ? ['-L'] : []),
            ...($bsd ? ['-f', '%d %i %z %m %c %.9Fm'] : ['--format=%d %i %s %Y %Z %y']),
            '--',
            ...$paths,
        ]);
        $lines = explode("\n", rtrim($result['output'], "\n"));
        if ($result['status'] !== 0 || count($lines) !== count($paths)) {
            return [];
        }
        $times = [];
        foreach ($paths as $index => $path) {
            $matches = [];
            if (
                preg_match('/^(\d+) (\d+) (\d+) (-?\d+) (-?\d+) .*?\.(\d{9})(?: |$)/', $lines[$index] ?? '', $matches)
                !== 1
            ) {
                return [];
            }
            $times[$path] = [
                'time' => ((int) $matches[4] * 1_000_000_000) + (int) $matches[6],
                'signature' => [
                    (int) $matches[1],
                    (int) $matches[2],
                    (int) $matches[3],
                    (int) $matches[4],
                    (int) $matches[5],
                ],
            ];
        }
        return $times;
    }

    private static function prefetch(string $directory, string $key): void
    {
        $entries = @opendir($directory);
        $paths = [$key];
        $bytes = strlen($key) + 1;
        if (is_resource($entries)) {
            $entry = false;
            try {
                while (($entry = readdir($entries)) !== false) {
                    $candidate = $directory . '/' . $entry;
                    if (
                        $entry === '.'
                        || $entry === '..'
                        || $candidate === $key
                        || isset(self::$preciseTimes[$candidate])
                        || !file_exists($candidate)
                    ) {
                        continue;
                    }
                    $bytes += strlen($candidate) + 1;
                    if (count($paths) >= self::BATCH_SIZE || $bytes > 65_536) {
                        break;
                    }
                    $paths[] = $candidate;
                }
                if ($entry === false) {
                    self::$directories[$directory] = true;
                }
            } finally {
                closedir($entries);
            }
        } else {
            self::$directories[$directory] = true;
        }
        foreach (self::preciseBatch($paths) as $name => $record) {
            self::$preciseTimes[$name] = $record;
        }
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
