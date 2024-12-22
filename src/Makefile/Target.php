<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

readonly final class Target
{
    /**
     * @param list<Command> $commands
     */
    public function __construct(
        public string $name,
        public int $startLineIndex,
        public int $endLineIndex,
        public array $commands,
    ) {}

    public function runWith(ShellExecInterface $shellExec): void
    {
        foreach ($this->commands as $command) {
            $command->runWith($shellExec);
        }
    }
}