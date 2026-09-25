<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_filter;
use function array_key_exists;
use function array_values;
use function basename;
use function in_array;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function usort;

/** Candidate branches are isolated: rejected chains cannot install rules or file paths. */
final class RuleSearch
{
    public SearchState $state;

    public function __construct(
        private readonly Makefile $makefile,
        private readonly Filesystem $filesystem,
        private readonly Output $output,
    ) {
        $this->state = new SearchState();
        foreach ($makefile->scopes->mentioned() as $name) {
            $this->state->mentioned[$name] = true;
        }
        foreach ($makefile->targets as $target) {
            $this->state->mentioned[$target->name] = true;
            foreach ($target->rules as $rule) {
                foreach ($rule->prerequisites->sequence as $dependency) {
                    if (!str_contains($dependency, '$')) {
                        $this->state->mentioned[$dependency] = true;
                    }
                }
            }
        }
    }

    /** @throws MakefileErrorException */
    public function resolve(string $name, VariableScope $scope): ?Target
    {
        if (array_key_exists($name, $this->state->targets)) {
            return $this->state->targets[$name];
        }
        $expander = new VariableExpander($scope->context, $this->output, scope: $scope);
        $this->locate($name, $expander, $this->state);
        $explicit =
            $this->makefile->targetsByName[$name]
            ?? $this->makefile->targetsByName[$this->state->paths[$name] ?? $name]
            ?? null;
        if ($explicit?->isPhony === true) {
            return $this->state->targets[$name] = $explicit;
        }
        $path = $this->state->paths[$name] ?? $name;
        $searchName = str_starts_with($name, '-l')
        || $this->makefile->paths->retain($path, $expander)
        || isset($this->makefile->targetsByName[$path])
            ? $path
            : $name;
        $rules = [];
        foreach ($explicit->rules ?? [new BuildRule()] as $rule) {
            if ($rule->recipe === null && !isset($this->state->terminal[$name])) {
                $rule = SecondaryExpansion::explicit($name, $rule, $expander, $this->filesystem);
                foreach ($rule->prerequisites->sequence as $dependency) {
                    $this->state->mentioned[$dependency] = true;
                }
                $implicit = $this->implicit($searchName, $rule, $scope, [], $this->state, false) ?? $this->implicit(
                    $searchName,
                    $rule,
                    $scope,
                    [],
                    $this->state,
                    true,
                );
                if ($implicit !== null) {
                    $rule = $implicit;
                }
            }
            if (
                $explicit === null
                && $rule->recipe === null
                && !isset($this->state->paths[$name])
                && isset($this->makefile->targetsByName['.DEFAULT'])
            ) {
                $rule = new BuildRule(
                    recipe: $this->makefile->targetsByName['.DEFAULT']->rules[0]->recipe ?? null,
                    firstPrerequisite: $name,
                );
            }
            if ($explicit !== null || $rule->recipe !== null) {
                $rules[] = $rule;
            }
        }
        return $this->state->targets[$name] = $rules === [] ? null : new Target($name, $rules);
    }

    /** @param array<int, true> $used
     * @throws MakefileErrorException
     */
    private function accept(
        Prerequisites $prerequisites,
        ImplicitCandidate $candidate,
        ?BuildRule $explicit,
        VariableScope $scope,
        array $used,
        SearchState &$branch,
        bool $chain,
        bool $compatibility,
    ): bool {
        foreach ($prerequisites->sequence as $dependency) {
            $child = $this->makefile->scopes->scope($dependency, $scope->inherit(), $this->output);
            if (
                isset($this->makefile->targetsByName[$dependency])
                || in_array($dependency, $explicit->prerequisites->sequence ?? [], true)
                || $this->locate(
                    $dependency,
                    new VariableExpander($child->context, $this->output, scope: $child),
                    $branch,
                )
                || $compatibility && isset($branch->mentioned[$dependency])
            ) {
                if ($candidate->pattern->rule->doubleColon) {
                    $branch->terminal[$dependency] = true;
                }
                continue;
            }
            if (!$chain) {
                return false;
            }
            $found = $this->implicit(
                $dependency,
                null,
                $child,
                $used + [$candidate->index => true],
                $branch,
                $compatibility,
            );
            if ($found === null) {
                return false;
            }
            $branch->targets[$dependency] = new Target($dependency, [$found]);
            if (!isset($branch->mentioned[$dependency]) && !in_array($dependency, $prerequisites->literal, true)) {
                $branch->intermediates[$dependency] = true;
            }
        }
        return true;
    }

    /** @param array<int, true> $used
     * @return list<ImplicitCandidate>
     */
    private function candidates(string $name, array $used): array
    {
        $candidates = [];
        $specific = false;
        foreach ($this->makefile->patterns as $index => $pattern) {
            foreach ($pattern->names as $text) {
                $hasDirectory = str_contains($text, '/');
                $stem = new Pattern($text)->match($hasDirectory ? $name : basename($name));
                if ($stem === null || $stem === '') {
                    continue;
                }
                if ($text !== '%') {
                    $specific = true;
                }
                if (
                    $pattern->rule->recipe === null
                    || isset($used[$index])
                    || $used !== [] && $text === '%' && !$pattern->rule->doubleColon
                ) {
                    continue;
                }
                $candidates[] = new ImplicitCandidate(
                    $pattern,
                    $index,
                    $stem,
                    $hasDirectory ? '' : substr($name, 0, strlen($name) - strlen(basename($name))),
                    $text === '%',
                );
            }
        }
        if ($specific) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn(ImplicitCandidate $candidate): bool => (
                    !$candidate->matchAnything || $candidate->pattern->rule->doubleColon
                ),
            ));
        }
        usort(
            $candidates,
            static fn(ImplicitCandidate $a, ImplicitCandidate $b): int => (
                strlen($a->directory . $a->stem) <=> strlen($b->directory . $b->stem)
            ),
        );
        return $candidates;
    }

    /** @param array<int, true> $used
     * @throws MakefileErrorException
     */
    private function implicit(
        string $name,
        ?BuildRule $explicit,
        VariableScope $scope,
        array $used,
        SearchState &$state,
        bool $compatibility,
    ): ?BuildRule {
        $candidates = $this->candidates($name, $used);
        $expanded = [];
        foreach ([false, true] as $chain) {
            foreach ($candidates as $candidate) {
                if ($chain && $candidate->pattern->rule->doubleColon) {
                    continue;
                }
                $key = $candidate->index . ':' . $candidate->directory . ':' . $candidate->stem;
                $branch = clone $state;
                $expanded[$key] ??= [];
                $rule = $candidate->expand(
                    $name,
                    $explicit,
                    new VariableExpander($scope->context, $this->output, scope: $scope),
                    $this->filesystem,
                    /** @throws MakefileErrorException */
                    function (Prerequisites $prerequisites) use (
                        $candidate,
                        $explicit,
                        $scope,
                        $used,
                        &$branch,
                        $chain,
                        $compatibility,
                    ): bool {
                        return $this->accept(
                            $prerequisites,
                            $candidate,
                            $explicit,
                            $scope,
                            $used,
                            $branch,
                            $chain,
                            $compatibility,
                        );
                    },
                    $expanded[$key],
                );
                if ($rule === null) {
                    continue;
                }
                foreach ($rule->prerequisites->literal as $literal) {
                    $branch->mentioned[$literal] = true;
                    unset($branch->intermediates[$literal]);
                }
                $state = $branch;
                return new BuildRule(
                    $rule->prerequisites->merge($explicit->prerequisites ?? new Prerequisites()),
                    $rule->recipe,
                    $rule->doubleColon,
                    $rule->stem,
                    $rule->group,
                    implicit: true,
                );
            }
        }
        return null;
    }

    /** @throws MakefileErrorException */
    private function locate(string $name, VariableExpander $expander, SearchState $state): bool
    {
        $path = $this->makefile->paths->find($name, $this->filesystem, $expander, $this->makefile->targetsByName);
        $state->existed[$name] ??= $path !== null && $this->filesystem->exists($path);
        if ($path === null) {
            return false;
        }
        $state->paths[$name] = $path;
        return true;
    }
}
