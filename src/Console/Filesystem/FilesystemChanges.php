<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Filesystem;

use function getmypid;

/**
 * Timestamp prefetch is reusable only between operations that can change files.
 * While any recipe is running, including a suspended fiber, reads must stay fresh.
 */
final class FilesystemChanges
{
    private static int $version = 0;

    private static int $writers = 0;

    private static int|false|null $owner = null;

    public static function active(): bool
    {
        return self::$writers !== 0;
    }

    public static function begin(): void
    {
        self::reset();
        self::$writers++;
    }

    public static function end(): void
    {
        self::reset();
        self::$writers--;
    }

    public static function reset(): void
    {
        self::version();
        self::$version++;
    }

    public static function version(): int
    {
        $owner = getmypid();
        if (self::$owner !== $owner) {
            self::$owner = $owner;
            self::$writers = 0;
            self::$version++;
        }
        return self::$version;
    }
}
