<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_filter;
use function array_key_exists;
use function array_values;
use function basename;
use function str_contains;
use function strlen;
use function substr;
use function usort;

final readonly class Makefile
{
    /** @var array<string, Target> */
    private array $targetsByName;

    /**
     * @param list<Target> $targets
     * @param list<Variable> $variables
     * @param list<Target> $patterns
     */
    public function __construct(
        public array $targets = [],
        public array $variables = [],
        public ?string $defaultGoal = null,
        private array $patterns = [],
        private Exports $exports = new Exports(),
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
                    ($this->resolveTarget($target, $filesystem)->commands ?? []) === []
                        ? "Nothing to be done for `$target'."
                        : "`$target' is up to date.",
                );
            }
        }
    }

    /** @param array<int, true> $usedPatterns */
    private function resolveTarget(string $name, Filesystem $filesystem, array $usedPatterns = []): ?Target
    {
        $explicit = $this->targetsByName[$name] ?? null;
        if ($explicit !== null && ($explicit->hasRecipe || $explicit->commands !== [] || $explicit->isPhony)) {
            return $explicit;
        }
        $candidates = [];
        foreach ($this->patterns as $index => $pattern) {
            if ($pattern->commands === [] || isset($usedPatterns[$index])) {
                continue;
            }
            $hasDirectory = str_contains($pattern->name, '/');
            $stem = new Pattern($pattern->name)->match($hasDirectory ? $name : basename($name));
            if ($stem === null || $stem === '') {
                continue;
            }
            $directory = $hasDirectory ? '' : substr($name, 0, strlen($name) - strlen(basename($name)));
            $dependencies = [];
            foreach ($pattern->dependencies as $dependency) {
                $dependencies[] =
                    (str_contains($dependency, '%') ? $directory : '') . new Pattern($dependency)->substitute($stem);
            }
            $candidates[] = [new Target($name, $dependencies, $pattern->commands, false, $directory . $stem), $index];
        }
        usort($candidates, static fn(array $a, array $b): int => strlen($a[0]->stem) <=> strlen($b[0]->stem));
        foreach ([false, true] as $allowChaining) {
            foreach ($candidates as [$candidate, $index]) {
                foreach ($candidate->dependencies as $dependency) {
                    if (
                        !$filesystem->exists($dependency)
                        && !isset($this->targetsByName[$dependency])
                        && (
                            !$allowChaining
                            || $this->resolveTarget($dependency, $filesystem, $usedPatterns + [$index => true]) === null
                        )
                    ) {
                        continue 2;
                    }
                }
                return new Target(
                    $name,
                    [...$candidate->dependencies, ...($explicit->dependencies ?? [])],
                    $candidate->commands,
                    false,
                    $candidate->stem,
                );
            }
        }
        return $explicit;
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
        $target = $this->resolveTarget($name, $filesystem);
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
                        $target->stem,
                        $target->hasRecipe,
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
            $rebuilt = $target->run(
                $shell,
                $filesystem,
                $output,
                $this->variables,
                $dependenciesRebuilt,
                $this->exports,
            );
            $commandsExecuted = $commandsExecuted || $rebuilt && $target->commands !== [];
            return $results[$name] =
                $rebuilt && ($target->commands !== [] || $target->isPhony || !$filesystem->exists($name));
        } finally {
            unset($visiting[$name]);
        }
    }
}
