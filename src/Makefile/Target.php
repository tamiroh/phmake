<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final readonly class Target
{
    /**
     * @param list<Target> $dependencies
     * @param list<string> $commands
     */
    public function __construct(
        public string $name,
        public array $dependencies,
        public int $startLineIndex,
        public int $endLineIndex,
        public array $commands,
    ) {}

    public function run(Shell $shell, Filesystem $filesystem, Output $output): bool
    {
        $rebuilt = false;

        foreach ($this->dependencies as $dependency) {
            $dependencyRunResult = $dependency->run($shell, $filesystem, $output);
            $rebuilt = $rebuilt || $dependencyRunResult;
        }

        if (!$filesystem->exists($this->name) || $rebuilt || $this->isAnyDependencyNewerThanTarget($filesystem)) {
            foreach ($this->commands as $command) {
                $output->writeLine($command);
                $result = $shell->exec($command);
                $output->write($result);
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
