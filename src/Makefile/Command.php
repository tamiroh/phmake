<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

readonly final class Command
{
    public function __construct(
        public string $command,
    ) {}

    public function run(Shell $shell): void
    {
        echo $this->command . PHP_EOL;
        echo $shell->exec($this->command);
    }
}