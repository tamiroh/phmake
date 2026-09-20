<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

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
    ): bool {
        if (!$this->isPhony && !$dependenciesRebuilt && !$this->needsRebuild($filesystem)) {
            return false;
        }

        foreach ($this->commands as $command) {
            $exitCode = $command->run($shell, $output, $variables);
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
