<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Tamiroh\Phmake\Makefile\Shell;

final class FakeShell implements Shell
{
    /** @var list<string> */
    public array $commands = [];

    public function exec(string $command): void
    {
        $this->commands[] = $command;
    }
}
