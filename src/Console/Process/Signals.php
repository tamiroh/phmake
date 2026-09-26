<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Symfony\Component\Process\Process;
use Tamiroh\Phmake\Makefile\Execution\InterruptedException;

use function array_reverse;
use function function_exists;
use function gc_collect_cycles;
use function getmypid;
use function pcntl_async_signals;
use function pcntl_signal;
use function posix_kill;
use function preg_match_all;
use function proc_get_status;
use function proc_terminate;

use const PREG_SET_ORDER;
use const SIG_DFL;
use const SIGHUP;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

/**
 * Record signals without throwing from a handler into an arbitrary fiber.
 */
final class Signals
{
    public static int $received = 0;

    /** @var list<int> */
    private array $handled = [];

    private bool $asynchronous = false;

    public function __construct()
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            return;
        }
        self::$received = 0;
        $this->asynchronous = pcntl_async_signals();
        $this->handled = [SIGHUP, SIGINT, SIGQUIT, SIGTERM];
        foreach ($this->handled as $signal) {
            pcntl_signal($signal, static function (int $signal): void {
                self::$received = $signal;
            });
        }
        pcntl_async_signals(true);
    }

    /**
     * @throws InterruptedException
     */
    public static function check(): void
    {
        if (self::$received !== 0) {
            throw new InterruptedException(self::$received);
        }
    }

    /**
     * Signal descendants before their shell so that compound recipes cannot leave workers behind.
     *
     * @param resource $process
     */
    public static function stop(mixed $process): void
    {
        $pid = proc_get_status($process)['pid'];
        $listing = new Process(['ps', '-eo', 'pid=,ppid=']);
        if ($listing->run() === 0) {
            $rows = [];
            preg_match_all('/^\s*(\d+)\s+(\d+)\s*$/m', $listing->getOutput(), $rows, PREG_SET_ORDER);
            $descendants = [$pid => true];
            do {
                $added = false;
                foreach ($rows as $row) {
                    /** @var array{string, numeric-string, numeric-string} $row */
                    if (isset($descendants[(int) $row[2]]) && !isset($descendants[(int) $row[1]])) {
                        $descendants[(int) $row[1]] = true;
                        $added = true;
                    }
                }
            } while ($added);
            foreach (array_reverse($descendants, true) as $child => $_) {
                if ($child !== $pid) {
                    posix_kill($child, self::$received);
                }
            }
        }
        proc_terminate($process, self::$received);
    }

    public function finish(): void
    {
        if ($this->handled === []) {
            return;
        }
        foreach ($this->handled as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        pcntl_async_signals($this->asynchronous);
        if (self::$received !== 0) {
            // Release cycles containing scheduler fibers before restoring default termination.
            gc_collect_cycles();
            pcntl_signal(self::$received, SIG_DFL);
            if (($pid = getmypid()) !== false) {
                posix_kill($pid, self::$received);
            }
        }
    }
}
