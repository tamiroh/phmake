<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Output;

use Override;
use Tamiroh\Phmake\Makefile\Execution\Scheduling\ParallelOptions;
use Tamiroh\Phmake\Makefile\IO\RecipeOutput;

use const PHP_EOL;
use const STDOUT;

final class Output implements RecipeOutput
{
    public OutputBuffer $buffer;

    public ?string $directory = null;

    public function __construct(
        public bool $silent = false,
        private readonly int $level = 0,
        ParallelOptions $options = new ParallelOptions(),
        private readonly string $program = 'phmake',
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
        get => $this->program . ($this->level === 0 ? '' : "[$this->level]");
    }

    #[Override]
    public function write(string $text): void
    {
        $this->buffer->write($text);
    }

    public function writeDirectory(bool $entering, string $directory): void
    {
        $this->directory = $entering ? $directory : null;
        if ($this->buffer->options->sync === 'line' || $this->buffer->options->sync === 'target') {
            $this->buffer->directory = $entering ? [$this->prefix, $directory] : null;
            return;
        }
        StreamOutput::write(
            STDOUT,
            $this->prefix . ': ' . ($entering ? 'Entering' : 'Leaving') . " directory '$directory'" . PHP_EOL,
        );
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
