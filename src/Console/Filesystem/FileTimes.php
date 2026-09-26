<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Filesystem;

use function clearstatcache;
use function dirname;
use function filemtime;
use function is_link;
use function lstat;
use function max;
use function readlink;
use function str_starts_with;

/**
 * Include each link's timestamp when following a symbolic-link chain.
 */
final class FileTimes
{
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
}
