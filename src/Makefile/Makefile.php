<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final readonly class Makefile
{
    /** @var array<string, Target> */
    private array $targetsByName;

    /**
     * @param list<Target> $targets
     * @param list<Variable> $variables
     */
    public function __construct(
        public array $targets = [],
        public array $variables = [],
        public ?string $defaultGoal = null,
    ) {
        $indexed = [];
        foreach ($targets as $target) {
            $indexed[$target->name] = $target;
        }
        $this->targetsByName = $indexed;
    }

    /**
     * @param list<string> $targets
     * @throws MakefileErrorException
     * @throws CommandFailedException
     * @throws MakefileUpToDateException
     */
    public function run(array $targets, Shell $shell, Filesystem $filesystem, Output $output): void
    {
        if ($targets === []) {
            if ($this->defaultGoal === null) {
                throw new MakefileErrorException('No targets');
            }
            $targets = [$this->defaultGoal];
        }

        $results = [];
        $visiting = [];
        foreach ($targets as $target) {
            if (!$this->runTarget($target, $shell, $filesystem, $output, $results, $visiting)) {
                throw new MakefileUpToDateException($target);
            }
        }
    }

    /**
     * @param array<string, bool> $results
     * @param array<string, true> $visiting
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    private function runTarget(
        string $name,
        Shell $shell,
        Filesystem $filesystem,
        Output $output,
        array &$results,
        array &$visiting,
        ?string $neededBy = null,
    ): bool {
        if (array_key_exists($name, $results)) {
            return $results[$name];
        }
        if (isset($visiting[$name])) {
            throw new MakefileErrorException("Circular dependency involving `$name'");
        }
        $target = $this->targetsByName[$name] ?? null;
        if ($target === null) {
            if ($filesystem->exists($name)) {
                return $results[$name] = false;
            }
            throw new MakefileErrorException(
                "No rule to make target `$name'" . ($neededBy === null ? '' : ", needed by `$neededBy'"),
            );
        }

        $visiting[$name] = true;
        try {
            $dependenciesRebuilt = false;
            foreach ($target->dependencies as $dependency) {
                $rebuilt = $this->runTarget($dependency, $shell, $filesystem, $output, $results, $visiting, $name);
                $dependenciesRebuilt = $dependenciesRebuilt || $rebuilt;
            }
            return $results[$name] = $target->run($shell, $filesystem, $output, $this->variables, $dependenciesRebuilt);
        } finally {
            unset($visiting[$name]);
        }
    }
}
