<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final readonly class ExportingShell implements Shell
{
    /** @param list<Variable> $variables */
    public function __construct(
        private Shell $shell,
        private Exports $exports,
        private array $variables,
        private Output $output,
    ) {}

    /**
     * @param array<string, string|false> $environment
     * @throws MakefileErrorException
     */
    #[\Override]
    public function exec(string $command, array $environment = []): int
    {
        return $this->shell->exec($command, [
            ...$this->exports->environment($this->variables, $this->output),
            ...$environment,
        ]);
    }
}
