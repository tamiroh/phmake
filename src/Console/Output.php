<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Override;
use Tamiroh\Phmake\Makefile\Execution\ParallelOptions;
use Tamiroh\Phmake\Makefile\IO\RecipeOutput;

use const PHP_EOL;

final class Output implements RecipeOutput
{
    public readonly OutputBuffer $buffer;

    public function __construct(
        private readonly bool $silent = false,
        private readonly int $level = 0,
        ParallelOptions $options = new ParallelOptions(),
    ) {
        $this->buffer = new OutputBuffer($options);
    }

    #[Override]
    public function beginCommand(bool $recursive): void
    {
        $this->buffer->command(true, $recursive);
    }

    #[Override]
    public function beginTarget(): void
    {
        $this->buffer->beginTarget();
    }

    #[Override]
    public function endCommand(): void
    {
        $this->buffer->command(false);
    }

    #[Override]
    public function endTarget(): void
    {
        $this->buffer->endTarget();
    }

    public string $prefix {
        get => 'phmake' . ($this->level === 0 ? '' : "[$this->level]");
    }

    #[Override]
    public function write(string $text): void
    {
        $this->buffer->write($text);
    }

    public function writeDirectory(bool $entering, string $directory): void
    {
        if ($this->buffer->options->sync === 'line' || $this->buffer->options->sync === 'target') {
            $this->buffer->directory = $entering ? [$this->prefix, $directory] : null;
            return;
        }
        echo $this->prefix . ': ' . ($entering ? 'Entering' : 'Leaving') . " directory '$directory'" . PHP_EOL;
    }

    #[Override]
    public function writeInfo(string $message): void
    {
        $this->writeLine($this->prefix . ": $message");
    }

    #[Override]
    public function writeLine(string $line): void
    {
        if (!$this->silent) {
            $this->buffer->write($line . PHP_EOL);
        }
    }

    #[Override]
    public function writeWarning(string $message, ?string $source = null): void
    {
        $this->buffer->write(($source ?? $this->prefix) . ": $message" . PHP_EOL, true);
    }
}
