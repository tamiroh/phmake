<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use function escapeshellarg;
use function explode;
use function file_exists;
use function getenv;
use function in_array;
use function is_array;
use function is_dir;
use function is_executable;
use function str_contains;
use function strlen;

/**
 * Simple POSIX commands can execute directly; shell syntax retains the configured shell.
 */
final readonly class CommandInvocation
{
    /**
     * @param non-empty-list<string>|string $command
     */
    private function __construct(
        public array|string $command,
    ) {}

    public static function parse(string $text, string $shell, string $flags): self
    {
        $fallback = new self('exec ' . $shell . ' ' . $flags . ' ' . escapeshellarg($text));
        if ($shell !== '/bin/sh' || !in_array($flags, ['-c', '-ec'], true)) {
            return $fallback;
        }
        $arguments = [];
        $word = '';
        $quoted = false;
        $present = false;
        for ($index = 0; $index < strlen($text); $index++) {
            $character = $text[$index];
            if ($character === "'") {
                $quoted = !$quoted;
                $present = true;
            } elseif ($quoted) {
                $word .= $character;
            } elseif ($character === '\\' && isset($text[$index + 1])) {
                $character = $text[++$index];
                if ($character !== "\n") {
                    $word .= $character;
                    $present = true;
                }
            } elseif (str_contains(" \t", $character)) {
                if ($present) {
                    $arguments[] = $word;
                    $word = '';
                    $present = false;
                }
            } elseif (
                str_contains("#;\"*?[]&|<>(){}$`^~!\n\\", $character)
                || $arguments === [] && $character === '='
            ) {
                return $fallback;
            } else {
                $word .= $character;
                $present = true;
            }
        }
        if ($present) {
            $arguments[] = $word;
        }
        if (
            $quoted
            || $arguments === []
            || in_array(
                $arguments[0],
                [
                    '.',
                    ':',
                    'alias',
                    'bg',
                    'break',
                    'case',
                    'cd',
                    'command',
                    'continue',
                    'eval',
                    'exec',
                    'exit',
                    'export',
                    'fc',
                    'fg',
                    'for',
                    'getopts',
                    'hash',
                    'if',
                    'jobs',
                    'login',
                    'logout',
                    'read',
                    'readonly',
                    'return',
                    'set',
                    'shift',
                    'test',
                    'times',
                    'trap',
                    'type',
                    'ulimit',
                    'umask',
                    'unalias',
                    'unset',
                    'wait',
                    'while',
                ],
                true,
            )
        ) {
            return $fallback;
        }
        return new self($arguments);
    }

    /**
     * @param array<string, string|false> $environment
     */
    public function error(array $environment): ?string
    {
        if (!is_array($this->command)) {
            return null;
        }
        $name = $this->command[0];
        $permission = false;
        $searchPath = $environment['PATH'] ?? getenv('PATH');
        foreach (str_contains($name, '/')
            ? [null]
            : explode(':', $searchPath === false ? '/bin:/usr/bin' : $searchPath) as $directory) {
            $path = $directory === null || $directory === '' ? $name : $directory . '/' . $name;
            if (is_executable($path) && !is_dir($path)) {
                return null;
            }
            $permission = $permission || file_exists($path);
        }
        return $name . ': ' . ($permission ? 'Permission denied' : 'No such file or directory');
    }

    /**
     * @pure
     *
     * Preserve exec's shell-script fallback, which PHP's array-form proc_open omits.
     * Positional arguments prevent the launcher from interpreting command contents.
     *
     * @return non-empty-list<string>|string
     */
    public function launch(): array|string
    {
        return is_array($this->command) ? ['/bin/sh', '-c', 'exec "$@"', 'sh', ...$this->command] : $this->command;
    }
}
