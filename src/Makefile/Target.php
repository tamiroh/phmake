<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

readonly final class Target
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
    ) {}

    public function run(ShellExecInterface $shellExec): bool
    {
        $rebuilt = false;

        foreach ($this->dependencies as $dependency) {
            $dependencyRunResult = $dependency->run($shellExec);
            $rebuilt = $rebuilt || $dependencyRunResult;
        }

        if (! file_exists($this->name) || $rebuilt || $this->isAnyDependencyNewerThanTarget()) {
            foreach ($this->commands as $command) {
                $command->run($shellExec);
            }
            return true;
        } else {
            return false;
        }
    }

    private function isAnyDependencyNewerThanTarget(): bool
    {
        $targetLastModified = @filemtime($this->name);
        if ($targetLastModified === false) {
            return true;
        }

        foreach ($this->dependencies as $dependency) {
            $dependencyLastModified = @filemtime($dependency->name);
            if ($dependencyLastModified === false) {
                return true;
            }

            if ($dependencyLastModified > $targetLastModified) {
                return true;
            }
        }

        return false;
    }
}