<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Tamiroh\Phmake\Makefile\Shell;

final class FakeShell implements Shell
{
    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, int> */
    public array $exitCodes = [];

    public function exec(string $command): int
    {
        $this->commands[] = $command;

        return $this->exitCodes[$command] ?? 0;
    }
}
