<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Override;
use Tamiroh\Phmake\Makefile\Evaluation\Exports;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\IO\ShellResult;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

final readonly class ExportingShell implements Shell
{
    public function __construct(
        private Shell $shell,
        private Exports $exports,
        private VariableExpander $variables,
        private ?Output $output,
    ) {}

    /**
     * @param array<string, string|false> $environment
     *
     * @throws MakefileErrorException
     */
    #[Override]
    public function capture(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
    ): ShellResult {
        $previous = $this->variables->context->shellEnvironment;
        $this->variables->context->shellEnvironment = true;
        try {
            $environment = [...$this->exports->environment($this->variables, $this->output), ...$environment];
        } finally {
            $this->variables->context->shellEnvironment = $previous;
        }
        return $this->shell->capture(
            $command,
            $environment,
            $this->shellName($shell),
            $this->variables->variable('.SHELLFLAGS') === null ? $flags : $this->variables->expand('$(.SHELLFLAGS)'),
        );
    }

    /**
     * @param array<string, string|false> $environment
     *
     * @throws MakefileErrorException
     */
    #[Override]
    public function exec(string $command, array $environment = [], string $shell = '/bin/sh', string $flags = '-c'): int
    {
        return $this->shell->exec(
            $command,
            [
                ...$this->exports->environment($this->variables, $this->output),
                ...$environment,
            ],
            $this->shellName($shell),
            $this->variables->variable('.SHELLFLAGS') === null ? $flags : $this->variables->expand('$(.SHELLFLAGS)'),
        );
    }

    /**
     * @throws MakefileErrorException
     */
    private function shellName(string $fallback): string
    {
        $name = $this->variables->expand('$(SHELL)');
        return $name === '' ? $fallback : $name;
    }
}
