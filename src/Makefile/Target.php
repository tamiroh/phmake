<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_unique;
use function implode;

final readonly class Target
{
    /**
     * @param list<string> $dependencies
     * @param list<Command> $commands
     */
    public function __construct(
        public string $name,
        public array $dependencies,
        public array $commands,
        public bool $isPhony,
        public string $stem = '',
        public bool $hasRecipe = false,
    ) {}

    /**
     * @param list<Variable> $variables
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(
        Shell $shell,
        Filesystem $filesystem,
        Output $output,
        array $variables = [],
        bool $dependenciesRebuilt = false,
        Exports $exports = new Exports(),
    ): bool {
        if (!$this->isPhony && !$dependenciesRebuilt && !$this->needsRebuild($filesystem)) {
            return false;
        }

        $variables = [
            ...$variables,
            new Variable('@', $this->name, false, 'automatic'),
            new Variable('<', $this->dependencies[0] ?? '', false, 'automatic'),
            new Variable('^', implode(' ', array_unique($this->dependencies)), false, 'automatic'),
            new Variable('+', implode(' ', $this->dependencies), false, 'automatic'),
            new Variable('*', $this->stem, false, 'automatic'),
        ];
        $commands = [];
        foreach ($this->commands as $command) {
            $commands[] = $command->expand($variables, $output);
        }
        $shell = new ExportingShell($shell, $exports, $variables, $output);
        foreach ($commands as $command) {
            $exitCode = $command->run($shell, $output);
            if ($exitCode !== 0) {
                throw new CommandFailedException($this->name, $exitCode);
            }
        }
        return true;
    }

    private function needsRebuild(Filesystem $filesystem): bool
    {
        $modifiedAt = $filesystem->lastModified($this->name);
        if ($modifiedAt === null) {
            return true;
        }
        foreach ($this->dependencies as $dependency) {
            $dependencyModifiedAt = $filesystem->lastModified($dependency);
            if ($dependencyModifiedAt === null || $dependencyModifiedAt > $modifiedAt) {
                return true;
            }
        }
        return false;
    }
}
