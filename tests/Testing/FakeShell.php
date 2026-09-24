<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Tamiroh\Phmake\Makefile\Shell;

final class FakeShell implements Shell
{
    /** @var list<string> */
    public private(set) array $commands = [];

    /** @var list<array<string, string|false>> */
    public private(set) array $environments = [];

    /** @var array<string, int> */
    public array $exitCodes = [];

    /** @param array<string, string|false> $environment */
    #[\Override]
    public function exec(string $command, array $environment = []): int
    {
        $this->commands[] = $command;
        $this->environments[] = $environment;

        return $this->exitCodes[$command] ?? 0;
    }
}
