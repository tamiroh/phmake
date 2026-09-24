<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function basename;
use function count;
use function str_contains;
use function strlen;
use function substr;
use function usort;

final readonly class Makefile
{
    /** @var array<string, Target> */
    public array $targetsByName;

    /**
     * @param list<Target> $targets
     * @param list<Variable> $variables
     * @param list<PatternRule> $patterns
     */
    public function __construct(
        public array $targets = [],
        public array $variables = [],
        public ?string $defaultGoal = null,
        private array $patterns = [],
        public Exports $exports = new Exports(),
        public ?EvaluationContext $context = null,
    ) {
        $indexed = [];
        foreach ($targets as $target) {
            $indexed[$target->name] = $target;
        }
        $this->targetsByName = $indexed;
    }

    /** @param array<int, true> $usedPatterns */
    public function resolveTarget(string $name, Filesystem $filesystem, array $usedPatterns = []): ?Target
    {
        $explicit = $this->targetsByName[$name] ?? null;
        if ($explicit !== null) {
            if ($explicit->isPhony) {
                return $explicit;
            }
            $rules = [];
            foreach ($explicit->rules as $rule) {
                $rules[] = $rule->recipe !== null
                    ? $rule
                    : $this->resolveImplicit($name, $filesystem, $rule, $usedPatterns) ?? $rule;
            }
            return new Target($name, $rules);
        }
        $implicit = $this->resolveImplicit($name, $filesystem, null, $usedPatterns);
        if ($implicit !== null) {
            return new Target($name, [$implicit]);
        }
        if (!$filesystem->exists($name) && isset($this->targetsByName['.DEFAULT'])) {
            return new Target($name, [new BuildRule(
                recipe: $this->targetsByName['.DEFAULT']->rules[0]->recipe ?? null,
                firstPrerequisite: $name,
            )]);
        }
        return null;
    }

    /** @param list<string> $targets
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(array $targets, Shell $shell, Filesystem $filesystem, Output $output): void
    {
        new Build($this, $shell, $filesystem, $output)->run($targets);
    }

    /** @param array<int, true> $usedPatterns */
    private function resolveImplicit(
        string $name,
        Filesystem $filesystem,
        ?BuildRule $explicit,
        array $usedPatterns,
    ): ?BuildRule {
        $candidates = [];
        foreach ($this->patterns as $index => $pattern) {
            if ($pattern->rule->recipe === null || isset($usedPatterns[$index])) {
                continue;
            }
            foreach ($pattern->names as $targetPattern) {
                $hasDirectory = str_contains($targetPattern, '/');
                $stem = new Pattern($targetPattern)->match($hasDirectory ? $name : basename($name));
                if ($stem === null || $stem === '') {
                    continue;
                }
                $directory = $hasDirectory ? '' : substr($name, 0, strlen($name) - strlen(basename($name)));
                $candidates[] = [
                    new BuildRule(
                        new Prerequisites(
                            $this->substitute($pattern->rule->prerequisites->normal, $stem, $directory),
                            $this->substitute($pattern->rule->prerequisites->orderOnly, $stem, $directory),
                            $pattern->rule->prerequisites->expressions,
                        ),
                        $pattern->rule->recipe,
                        $pattern->rule->doubleColon,
                        $directory . $stem,
                        count($pattern->names) > 1 ? $this->substitute($pattern->names, $stem, $directory) : [],
                    ),
                    $index,
                ];
            }
        }
        usort($candidates, static fn(array $a, array $b): int => strlen($a[0]->stem) <=> strlen($b[0]->stem));
        foreach ([false, true] as $allowChaining) {
            foreach ($candidates as [$candidate, $index]) {
                foreach ([
                    ...$candidate->prerequisites->normal,
                    ...$candidate->prerequisites->orderOnly,
                ] as $dependency) {
                    if (
                        !$filesystem->exists($dependency)
                        && (
                            $candidate->doubleColon
                            || !isset($this->targetsByName[$dependency])
                            && (
                                !$allowChaining
                                || $this->resolveTarget($dependency, $filesystem, $usedPatterns + [$index => true])
                                === null
                            )
                        )
                    ) {
                        continue 2;
                    }
                }
                $prerequisites = $candidate->prerequisites->merge($explicit->prerequisites ?? new Prerequisites());
                return new BuildRule(
                    $prerequisites,
                    $candidate->recipe,
                    $explicit->doubleColon ?? false,
                    $candidate->stem,
                    $candidate->group,
                );
            }
        }
        return null;
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private function substitute(array $names, string $stem, string $directory): array
    {
        $result = [];
        foreach ($names as $name) {
            $pattern = new Pattern($name);
            $result[] = ($pattern->hasWildcard() ? $directory : '') . $pattern->substitute($stem);
        }
        return $result;
    }
}
