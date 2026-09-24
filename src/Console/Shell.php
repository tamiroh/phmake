<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Closure;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tamiroh\Phmake\Makefile\Shell as ShellInterface;
use Tamiroh\Phmake\Makefile\ShellResult;

use function defined;
use function escapeshellarg;
use function fwrite;
use function stream_isatty;

final class Shell implements ShellInterface
{
    /** @param array<string, string|false> $environment */
    public function __construct(
        private readonly array $environment = [],
    ) {}

    /** @param array<string, string|false> $environment */
    #[\Override]
    public function capture(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
    ): ShellResult {
        $process = $this->process($command, $environment, $shell, $flags);
        $status = $this->run($process, static function (string $type, string $buffer): void {
            if ($type === SymfonyProcess::ERR) {
                fwrite(STDERR, $buffer);
            }
        });
        return new ShellResult($process->getOutput(), $status);
    }

    /** @param array<string, string|false> $environment */
    #[\Override]
    public function exec(string $command, array $environment = [], string $shell = '/bin/sh', string $flags = '-c'): int
    {
        $process = $this->process($command, $environment, $shell, $flags);

        if ($this->isStdoutTty() && SymfonyProcess::isTtySupported()) {
            try {
                $process->setTty(true);
            } catch (RuntimeException) {
            }
        }

        return $this->run($process, static function (string $type, string $buffer): void {
            if ($type === SymfonyProcess::ERR) {
                fwrite(STDERR, $buffer);
            } else {
                print $buffer;
            }
        });
    }

    private function isStdoutTty(): bool
    {
        return defined('STDOUT') && stream_isatty(STDOUT);
    }

    /** @param array<string, string|false> $environment */
    private function process(string $command, array $environment, string $shell, string $flags): SymfonyProcess
    {
        $process = SymfonyProcess::fromShellCommandline(
            'exec ' . $shell . ' ' . $flags . ' ' . escapeshellarg($command),
            env: [...$this->environment, ...$environment],
        );
        $process->setTimeout(null);
        return $process;
    }

    /** @param Closure(string, string): void $callback */
    private function run(SymfonyProcess $process, Closure $callback): int
    {
        try {
            return $process->run($callback);
        } catch (ProcessSignaledException $error) {
            return 128 + $error->getSignal();
        }
    }
}
