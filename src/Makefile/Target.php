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

    public function run(ShellExecInterface $shellExec, Filesystem $filesystem): bool
    {
        $rebuilt = false;

        foreach ($this->dependencies as $dependency) {
            $dependencyRunResult = $dependency->run($shellExec, $filesystem);
            $rebuilt = $rebuilt || $dependencyRunResult;
        }

        if (! $filesystem->exists($this->name) || $rebuilt || $this->isAnyDependencyNewerThanTarget($filesystem)) {
            foreach ($this->commands as $command) {
                $command->run($shellExec);
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