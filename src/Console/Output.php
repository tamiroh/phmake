<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Output as OutputInterface;

use function fwrite;

final class Output implements OutputInterface
{
    #[\Override]
    public function write(string $text): void
    {
        echo $text;
    }

    #[\Override]
    public function writeInfo(string $message): void
    {
        $this->writeLine("phmake: $message");
    }

    #[\Override]
    public function writeLine(string $line): void
    {
        echo $line . PHP_EOL;
    }

    #[\Override]
    public function writeWarning(string $message): void
    {
        fwrite(STDERR, "phmake: $message" . PHP_EOL);
    }
}
