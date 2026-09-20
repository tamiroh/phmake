<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final readonly class Target
{
    /**
     * @param list<Target> $dependencies
     * @param list<Command> $commands
     */
    public function __construct(
        public string $name,
        public array $dependencies,
        public int $startLineIndex,
        public int $endLineIndex,
        public array $commands,
        public bool $isPhony,
        public bool $hasRule = true,
    ) {}

    /**
     * @param list<Variable> $variables
     *
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    public function run(Shell $shell, Filesystem $filesystem, Output $output, array $variables = []): bool
    {
        if (!$this->hasRule) {
            return false;
        }

        $rebuilt = false;

        foreach ($this->dependencies as $dependency) {
            if (!$dependency->hasRule && !$filesystem->exists($dependency->name)) {
                throw new MakefileErrorException("No rule to make target `$dependency->name', needed by `$this->name'");
            }
            $dependencyRunResult = $dependency->run($shell, $filesystem, $output, $variables);
            $rebuilt = $rebuilt || $dependencyRunResult;
        }

        if (
            $this->isPhony
            || !$filesystem->exists($this->name)
            || $rebuilt
            || $this->isAnyDependencyNewerThanTarget($filesystem)
        ) {
            foreach ($this->commands as $command) {
                $expandedCommand = $command->expand($variables);
                $output->writeLine($expandedCommand);
                $exitCode = $shell->exec($expandedCommand);
                if ($exitCode !== 0) {
                    throw new CommandFailedException($this->name, $exitCode);
                }
            }
            return true;
        } else {
            return false;
        }
    }

    private function isAnyDependencyNewerThanTarget(Filesystem $filesystem): bool
    {
        $targetLastModified = $filesystem->lastModified($this->name);
        if ($targetLastModified === null) {
            return true;
        }

        foreach ($this->dependencies as $dependency) {
            $dependencyLastModified = $filesystem->lastModified($dependency->name);
            if ($dependencyLastModified === null) {
                return true;
            }

            if ($dependencyLastModified > $targetLastModified) {
                return true;
            }
        }

        return false;
    }
}
