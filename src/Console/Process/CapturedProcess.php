<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Closure;
use RuntimeException;
use Tamiroh\Phmake\Makefile\IO\ShellResult;

use function fclose;
use function feof;
use function fread;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_terminate;
use function stream_set_blocking;
use function usleep;

/**
 * Drain both output pipes while keeping captured commands independent of recipe stdin.
 */
final class CapturedProcess
{
    /**
     * @param non-empty-list<string>|string $command
     * @param array<string, string|false> $environment
     * @param Closure(string): void|null $stderr
     */
    public static function run(
        array|string $command,
        array $environment = [],
        ?Closure $stderr = null,
        bool $interruptible = true,
    ): ShellResult {
        $pipes = [];
        $process = ProcessLauncher::start(
            $command,
            $environment,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return new ShellResult('', 127);
        }
        try {
            if (isset($pipes[0])) {
                fclose($pipes[0]);
                unset($pipes[0]);
            }
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $output = '';
            $stopped = false;
            $exitCode = null;
            do {
                if ($interruptible && Signals::$received !== 0 && !$stopped) {
                    Signals::stop($process);
                    $stopped = true;
                }
                foreach ($pipes as $descriptor => $pipe) {
                    $buffer = fread($pipe, 8192);
                    if ($buffer === false) {
                        throw new RuntimeException('Cannot read process output');
                    }
                    if ($descriptor === 1) {
                        $output .= $buffer;
                    } elseif ($buffer !== '') {
                        $stderr?->__invoke($buffer);
                    }
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($pipes[$descriptor]);
                    }
                }
                $status = proc_get_status($process);
                if ($exitCode === null && !$status['running']) {
                    $exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
                }
                if ($pipes !== [] || $exitCode === null) {
                    usleep(1000);
                }
            } while ($pipes !== [] || $exitCode === null);
            return new ShellResult($output, $exitCode);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }
}
