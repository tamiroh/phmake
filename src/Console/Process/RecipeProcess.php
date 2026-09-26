<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Closure;
use Fiber;
use Tamiroh\Phmake\Makefile\Execution\InterruptedException;
use Tamiroh\Phmake\Makefile\Execution\Scheduling\Suspension;

use function fclose;
use function fopen;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function usleep;

/**
 * Recipe output goes directly to inherited streams, preserving inter-process order.
 */
final class RecipeProcess
{
    private static bool $inputBusy = false;

    /**
     * @param non-empty-list<string>|string $command
     * @param array<string, string|false> $environment
     * @param array{1?: resource, 2?: resource, 3?: resource, 4?: resource} $descriptors
     * @param Closure(int, ?int): void|null $observer
     *
     * @throws InterruptedException
     */
    public static function run(
        array|string $command,
        array $environment,
        array $descriptors = [],
        ?Closure $observer = null,
    ): int {
        Signals::check();
        $ownsInput = !self::$inputBusy;
        self::$inputBusy = true;
        $input = $ownsInput ? null : fopen('/dev/null', 'r');
        if (is_resource($input)) {
            $descriptors[0] = $input;
        }
        try {
            $pipes = [];
            $process = ProcessLauncher::start($command, $environment, $descriptors, $pipes);
            if (!is_resource($process)) {
                return 127;
            }
            $pid = proc_get_status($process)['pid'];
            $observer?->__invoke($pid, null);
            $stopped = false;
            do {
                if (Signals::$received !== 0 && !$stopped) {
                    Signals::stop($process);
                    $stopped = true;
                }
                if (Fiber::getCurrent() !== null) {
                    Suspension::until(static fn(): bool => true);
                } else {
                    usleep(1000);
                }
                $status = proc_get_status($process);
            } while ($status['running']);
            Signals::check();
            $exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
            $observer?->__invoke($pid, $exitCode);
            return $exitCode;
        } finally {
            if (isset($process) && is_resource($process)) {
                proc_close($process);
            }
            if (is_resource($input)) {
                fclose($input);
            }
            if ($ownsInput) {
                self::$inputBusy = false;
            }
        }
    }
}
