<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Fiber;
use Tamiroh\Phmake\Makefile\Execution\InterruptedException;
use Tamiroh\Phmake\Makefile\Execution\Suspension;

use function fclose;
use function fopen;
use function getenv;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function putenv;
use function usleep;

/**
 * Recipe output goes directly to inherited streams, preserving inter-process order.
 */
final class RecipeProcess
{
    private static bool $inputBusy = false;

    /**
     * @param array<string, string|false> $environment
     * @param array{1?: resource, 2?: resource, 3?: resource, 4?: resource} $descriptors
     *
     * @throws InterruptedException
     */
    public static function run(string $command, array $environment, array $descriptors = []): int
    {
        Signals::check();
        $ownsInput = !self::$inputBusy;
        self::$inputBusy = true;
        $input = $ownsInput ? null : fopen('/dev/null', 'r');
        if (is_resource($input)) {
            $descriptors[0] = $input;
        }
        try {
            $process = self::start($command, $environment, $descriptors);
            if (!is_resource($process)) {
                return 127;
            }
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
            return $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
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

    /**
     * Preserve empty environment values, which associative proc_open environments omit.
     * No fiber can run while the process environment is temporarily changed.
     *
     * @param array<array-key, string|false> $environment
     * @param array{0?: resource, 1?: resource, 2?: resource, 3?: resource, 4?: resource} $descriptors
     *
     * @return resource|false
     */
    private static function start(string $command, array $environment, array $descriptors = []): mixed
    {
        $previous = [];
        try {
            foreach ($environment as $name => $value) {
                $previous[$name] = getenv((string) $name);
                putenv($value === false ? (string) $name : $name . '=' . $value);
            }
            $pipes = [];
            // PHP supports arbitrary descriptor numbers; Mago's stub only lists 0-2.
            // https://www.php.net/manual/en/function.proc-open.php
            // @mago-expect analysis:possibly-invalid-argument
            return proc_open($command, $descriptors, $pipes);
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? (string) $name : $name . '=' . $value);
            }
        }
    }
}
