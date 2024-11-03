<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Tamiroh\Phmake\Console\Command;

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

    public function run(): void
    {
        foreach ($this->commands as $command) {
            $command->run();
        }
    }
}