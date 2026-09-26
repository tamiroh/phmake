<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Phar;
use Tamiroh\Phmake\Console\Input\CommandLine;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Console\Output\OutputBuffer;
use Tamiroh\Phmake\Makefile\Execution\Scheduling\ParallelOptions;
use Tamiroh\Phmake\Makefile\Reporting\DebugTrace;

use function array_filter;
use function array_values;
use function chdir;
use function dirname;
use function gc_collect_cycles;
use function implode;
use function pcntl_exec;
use function pcntl_get_last_error;
use function pcntl_strerror;
use function putenv;
use function str_starts_with;

use const PHP_BINARY;

/**
 * Replace the process while retaining the makefile originally read from standard input.
 */
final class ProcessRestart
{
    public static function execute(CommandLine $configuration, string $stdin, int $restarts, Output $output): never
    {
        gc_collect_cycles();
        $arguments = array_values(array_filter(
            $configuration->input->arguments,
            static fn(string $argument): bool => !str_starts_with($argument, '--temp-stdin='),
        ));
        $arguments[] = '--temp-stdin=' . $stdin;
        $executable = Phar::running(false);
        if ($executable === '') {
            $executable = PHP_BINARY;
            $arguments = [dirname(__DIR__, 3) . '/phmake', ...$arguments];
        }
        DebugTrace::write(
            $configuration->execution->reporting,
            $output,
            'b',
            'Re-executing: ' . $executable . ' ' . implode(' ', $arguments),
        );
        $output->buffer = new OutputBuffer(new ParallelOptions());
        putenv('MAKE_RESTARTS=' . ($output->directory === null ? '' : '-') . $restarts);
        if (!@chdir($configuration->input->directory)) {
            throw new RestartFailureException('cannot restore the original working directory');
        }
        @pcntl_exec($executable, $arguments);
        throw new RestartFailureException($executable . ': ' . pcntl_strerror(pcntl_get_last_error()));
    }
}
