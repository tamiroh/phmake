<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

readonly final class Command
{
    public function __construct(
        public string $command,
    ) {}

    public function runWith(ShellExecInterface $shellExec): void
    {
        echo $this->command . PHP_EOL;
        echo $shellExec->exec($this->command);
    }
}