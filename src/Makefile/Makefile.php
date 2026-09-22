<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_filter;
use function array_key_exists;
use function array_values;

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
            $commandsExecuted = false;
            $this->runTarget($target, $shell, $filesystem, $output, $results, $visiting, $commandsExecuted);
            if (!$commandsExecuted) {
                $output->writeInfo(
                    ($this->targetsByName[$target]->commands ?? []) === []
                        ? "Nothing to be done for `$target'."
                        : "`$target' is up to date.",
                );
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
        bool &$commandsExecuted,
        ?string $neededBy = null,
    ): bool {
        if (array_key_exists($name, $results)) {
            return $results[$name];
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
                if (isset($visiting[$dependency])) {
                    $output->writeWarning("Circular $name <- $dependency dependency dropped.");
                    $target = new Target(
                        $target->name,
                        array_values(array_filter(
                            $target->dependencies,
                            static fn(string $candidate): bool => $candidate !== $dependency,
                        )),
                        $target->commands,
                        $target->isPhony,
                    );
                    continue;
                }
                $rebuilt = $this->runTarget(
                    $dependency,
                    $shell,
                    $filesystem,
                    $output,
                    $results,
                    $visiting,
                    $commandsExecuted,
                    $name,
                );
                $dependenciesRebuilt = $dependenciesRebuilt || $rebuilt;
            }
            $rebuilt = $target->run($shell, $filesystem, $output, $this->variables, $dependenciesRebuilt);
            $commandsExecuted = $commandsExecuted || $rebuilt && $target->commands !== [];
            return $results[$name] = $rebuilt;
        } finally {
            unset($visiting[$name]);
        }
    }
}
