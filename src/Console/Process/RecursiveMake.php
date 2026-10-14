<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Closure;
use Phar;
use Tamiroh\Phmake\Console\Application;
use Tamiroh\Phmake\Engine\Execution\InterruptedException;
use Throwable;

use function array_key_exists;
use function array_slice;
use function chdir;
use function clearstatcache;
use function count;
use function dirname;
use function explode;
use function function_exists;
use function fwrite;
use function gc_collect_cycles;
use function getcwd;
use function getenv;
use function in_array;
use function ini_get;
use function ini_parse_quantity;
use function ini_set;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function memory_get_usage;
use function pcntl_exec;
use function pcntl_fork;
use function pcntl_sigprocmask;
use function pcntl_sigtimedwait;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function putenv;
use function realpath;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function usleep;

use const PHP_BINARY;
use const SIG_BLOCK;
use const SIG_SETMASK;
use const SIGCHLD;
use const STDERR;
use const WNOHANG;

/**
 * Recursive invocations of this program run in a forked copy instead of a new PHP process.
 * Starting PHP and compiling the program again dominates no-op recursive builds.
 */
final class RecursiveMake
{
    /**
     * Recognize `$(MAKE) ARGUMENTS` and `cd DIRECTORY && $(MAKE) ARGUMENTS` without shell expansions.
     *
     * @param array<string, string|false> $environment
     *
     * @return array{?string, list<string>}|null the directory to enter and the arguments
     */
    public static function parse(string $command, array $environment, string $shell, string $flags): ?array
    {
        if ($shell !== '/bin/sh' || $flags !== '-c' && $flags !== '-ec') {
            return null;
        }
        $words = self::words($command);
        if ($words === null) {
            return null;
        }
        $directory = null;
        if (($words[0] ?? null) === 'cd' && array_key_exists(2, $words) && $words[2] === null) {
            $directory = $words[1] ?? '';
            $search = $environment['CDPATH'] ?? getenv('CDPATH');
            // The shell resolves `..` against PWD rather than through symbolic links.
            if (
                $directory === ''
                || str_starts_with($directory, '-')
                || in_array('..', explode('/', $directory), true)
                || is_string($search) && $search !== '' && !str_starts_with($directory, '/')
            ) {
                return null;
            }
            $words = array_slice($words, 3);
        }
        $program = self::program();
        if (array_slice($words, 0, count($program)) !== $program) {
            return null;
        }
        $arguments = [];
        foreach (array_slice($words, count($program)) as $word) {
            if ($word === null) {
                return null;
            }
            $arguments[] = $word;
        }
        return [$directory, $arguments];
    }

    /**
     * The command that starts this program, as $(MAKE) names it.
     *
     * @return non-empty-list<string>
     */
    public static function program(): array
    {
        $executable = Phar::running(false);
        return $executable === '' ? [PHP_BINARY, dirname(__DIR__, 3) . '/phmake'] : [$executable];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string|false> $environment applied over the current environment
     * @param Closure(int, ?int): void $observer
     *
     * @throws InterruptedException
     *
     * @return ?int the exit status, or null when the command must run through the shell
     */
    public static function run(?string $directory, array $arguments, array $environment, Closure $observer): ?int
    {
        // The shell reports directories that cannot be entered.
        if ($directory !== null && !is_dir($directory) || !function_exists('pcntl_fork')) {
            return null;
        }
        Signals::check();
        // A blocked SIGCHLD stays pending, so the wait below wakes as soon as the child exits.
        $previous = [];
        pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $previous);
        $mask = is_array($previous) ? $previous : [];
        $result = -1;
        $status = 0;
        try {
            $pid = pcntl_fork();
            if ($pid === -1) {
                return null;
            }
            if ($pid === 0) {
                pcntl_sigprocmask(SIG_SETMASK, $mask, $previous);
                // The inherited handlers only record signals, which commands such as --format never check.
                Signals::reset();
                self::child($directory, $arguments, $environment);
            }
            $observer($pid, null);
            $stopped = false;
            while (($result = pcntl_waitpid($pid, $status, WNOHANG)) === 0) {
                if (Signals::$received !== 0 && !$stopped) {
                    Signals::stop($pid);
                    $stopped = true;
                }
                if (function_exists('pcntl_sigtimedwait')) {
                    pcntl_sigtimedwait([SIGCHLD], seconds: 1);
                } else {
                    usleep(200);
                }
            }
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $mask, $previous);
        }
        Signals::check();
        $exitCode = match (true) {
            $result === -1 || !is_int($status) => 127,
            pcntl_wifsignaled($status) => 128 + (int) pcntl_wtermsig($status),
            default => (int) pcntl_wexitstatus($status),
        };
        $observer($pid, $exitCode);
        return $exitCode;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string|false> $environment
     */
    private static function child(?string $directory, array $arguments, array $environment): never
    {
        foreach ($environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        self::enter($directory);
        clearstatcache(true);
        /**
         * Memory inherited from the parent should not count against this invocation.
         *
         * @mago-expect analysis:possibly-false-argument
         */
        $limit = ini_parse_quantity(ini_get('memory_limit'));
        if ($limit > 0) {
            ini_set('memory_limit', (string) ($limit + memory_get_usage(true)));
        }
        try {
            $status = new Application()->status($arguments);
        } catch (Throwable $error) {
            // Propagating would resume the parent's build inside this copy.
            fwrite(STDERR, 'PHP Fatal error:  Uncaught ' . $error . "\n");
            $status = 255;
        }
        // Exiting normally would free the inherited heap object by object, copying each page first.
        gc_collect_cycles();
        @pcntl_exec('/bin/sh', ['-c', 'exit ' . $status]);
        exit($status);
    }

    /**
     * Enter the directory and update PWD and OLDPWD as the POSIX shell does.
     */
    private static function enter(?string $directory): void
    {
        $physical = (string) getcwd();
        $pwd = getenv('PWD');
        $logical = is_string($pwd) && str_starts_with($pwd, '/') && realpath($pwd) === $physical ? $pwd : $physical;
        if ($directory !== null) {
            if (!@chdir($directory)) {
                fwrite(STDERR, "/bin/sh: 1: cd: can't cd to {$directory}\n");
                exit(2);
            }
            putenv('OLDPWD=' . $logical);
            $logical = self::normalize(str_starts_with($directory, '/') ? $directory : $logical . '/' . $directory);
            $physical = (string) getcwd();
            if (realpath($logical) !== $physical) {
                $logical = $physical;
            }
        }
        putenv('PWD=' . $logical);
    }

    private static function normalize(string $path): string
    {
        $normalized = '';
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                $normalized = substr($normalized, 0, (int) strrpos($normalized, '/'));
            } elseif ($part !== '' && $part !== '.') {
                $normalized .= '/' . $part;
            }
        }
        return $normalized === '' ? '/' : $normalized;
    }

    /**
     * Split quoted words; `&&` becomes null at index 2 and the parse fails on any other shell syntax.
     *
     * @return list<?string>|null
     */
    private static function words(string $text): ?array
    {
        $words = [];
        $word = '';
        $present = false;
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];
            if ($character === "'") {
                $end = strpos($text, "'", $index + 1);
                if ($end === false) {
                    return null;
                }
                $word .= substr($text, $index + 1, $end - $index - 1);
                $present = true;
                $index = $end;
            } elseif ($character === '"') {
                for ($index++; $index < $length && $text[$index] !== '"'; $index++) {
                    $character = $text[$index];
                    if ($character === '$' || $character === '`') {
                        return null;
                    }
                    if ($character === '\\' && isset($text[$index + 1]) && str_contains("\\\"\n", $text[$index + 1])) {
                        $character = $text[++$index];
                        if ($character === "\n") {
                            continue;
                        }
                    }
                    $word .= $character;
                }
                if ($index >= $length) {
                    return null;
                }
                $present = true;
            } elseif ($character === '\\' && isset($text[$index + 1])) {
                $character = $text[++$index];
                if ($character !== "\n") {
                    $word .= $character;
                    $present = true;
                }
            } elseif ($character === ' ' || $character === "\t") {
                if ($present) {
                    $words[] = $word;
                    $word = '';
                    $present = false;
                }
            } elseif ($character === '&' && ($text[$index + 1] ?? '') === '&' && !$present && count($words) === 2) {
                $words[] = null;
                $index++;
            } elseif (str_contains("#;*?[]&|<>(){}$`^~!\n\\", $character)) {
                return null;
            } else {
                $word .= $character;
                $present = true;
            }
        }
        if ($present) {
            $words[] = $word;
        }
        return $words;
    }
}
