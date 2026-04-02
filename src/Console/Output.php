<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Output as OutputInterface;

final class Output implements OutputInterface
{
    public function write(string $text): void
    {
        echo $text;
    }

    public function writeLine(string $line): void
    {
        echo $line . PHP_EOL;
    }
}
