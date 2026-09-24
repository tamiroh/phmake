<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tamiroh\Phmake\Makefile\Shell as ShellInterface;

use function defined;
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
    public function exec(string $command, array $environment = []): int
    {
        $process = SymfonyProcess::fromShellCommandline($command, env: [...$this->environment, ...$environment]);
        $process->setTimeout(null);

        if ($this->isStdoutTty() && SymfonyProcess::isTtySupported()) {
            try {
                $process->setTty(true);
            } catch (RuntimeException) {
            }
        }

        return $process->run(function ($type, string $buffer): void {
            match ($type) {
                SymfonyProcess::OUT => print $buffer,
                SymfonyProcess::ERR => fwrite(STDERR, $buffer),
            };
        });
    }

    private function isStdoutTty(): bool
    {
        return defined('STDOUT') && stream_isatty(STDOUT);
    }
}
