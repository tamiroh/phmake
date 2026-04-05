<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tamiroh\Phmake\Makefile\Shell as ShellInterface;

final class Shell implements ShellInterface
{
    public function exec(string $command): int
    {
        $process = SymfonyProcess::fromShellCommandline($command);

        if ($this->isStdoutTty() && SymfonyProcess::isTtySupported()) {
            try {
                $process->setTty(true);
            } catch (RuntimeException) {
            }
        }

        return $process->run(function (string $type, string $buffer): void {
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
