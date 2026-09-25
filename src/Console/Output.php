<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Override;
use Tamiroh\Phmake\Makefile\IO\Output as OutputInterface;

use function fwrite;

use const PHP_EOL;
use const STDERR;

final class Output implements OutputInterface
{
    public function __construct(
        private readonly bool $silent = false,
        private readonly int $level = 0,
    ) {}

    #[Override]
    public function write(string $text): void
    {
        echo $text;
    }

    public function writeDirectory(bool $entering, string $directory): void
    {
        echo $this->prefix() . ': ' . ($entering ? 'Entering' : 'Leaving') . " directory '$directory'" . PHP_EOL;
    }

    #[Override]
    public function writeInfo(string $message): void
    {
        $this->writeLine($this->prefix() . ": $message");
    }

    #[Override]
    public function writeLine(string $line): void
    {
        if (!$this->silent) {
            echo $line . PHP_EOL;
        }
    }

    #[Override]
    public function writeWarning(string $message, ?string $source = null): void
    {
        fwrite(STDERR, ($source ?? 'phmake') . ": $message" . PHP_EOL);
    }

    private function prefix(): string
    {
        return 'phmake' . ($this->level === 0 ? '' : "[$this->level]");
    }
}
