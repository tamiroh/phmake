<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Input;

use const PHP_OS_FAMILY;
use const PHP_VERSION;

final class Usage
{
    /**
     * @pure
     */
    public static function text(string $program): string
    {
        return (
            "Usage: $program [options] [target ...]\n"
            . "  -f FILE, --file=FILE       Read FILE as a makefile (- for standard input).\n"
            . "  -C DIR, --directory=DIR    Change directory before reading makefiles.\n"
            . "  -j [N], --jobs[=N]        Run recipes in parallel.\n"
            . "  -n, --dry-run             Print recipes without executing them.\n"
            . "  -k, --keep-going          Continue independent targets after errors.\n"
            . "  -s, --silent              Do not echo recipes.\n"
            . "  -rR                       Disable built-in rules and variables.\n"
            . "  -L, --check-symlink-times  Include symbolic-link timestamps.\n"
            . "  --debug[=FLAGS], --trace   Show build decisions.\n"
            . "  -h, --help                Show this help.\n"
            . "  -v, --version             Show version information.\n\n"
            . self::version()
        );
    }

    /**
     * @pure
     */
    public static function version(): string
    {
        return 'phmake (development)' . "\nBuilt for PHP " . PHP_VERSION . ' on ' . PHP_OS_FAMILY . "\n";
    }
}
