<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Search;

use Tamiroh\Phmake\Engine\Expansion\SecondaryExpansion;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Reporting\DebugTrace;
use Tamiroh\Phmake\Engine\Rule\ArchiveMember;
use Tamiroh\Phmake\Engine\Rule\BuildRule;
use Tamiroh\Phmake\Engine\Rule\Pattern;
use Tamiroh\Phmake\Engine\Rule\Prerequisites;
use Tamiroh\Phmake\Engine\Rule\Target;
use Tamiroh\Phmake\Engine\Search\Internal\ImplicitCandidate;
use Tamiroh\Phmake\Engine\Search\Internal\TargetAlias;
use Tamiroh\Phmake\Engine\Variable\VariableScope;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function basename;
use function count;
use function implode;
use function in_array;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function usort;

/**
 * Candidate branches are isolated: rejected chains cannot install rules or file paths.
 */
final class RuleSearch
{
    public SearchState $state;

    /** @var array<string, list<ImplicitCandidate>> */
    private array $candidates = [];

    private bool $compatible = false;

    public function __construct(
        private readonly Makefile $makefile,
        private readonly Filesystem $filesystem,
        private readonly Output $output,
    ) {
        $this->state = new SearchState();
        foreach ($makefile->targetVariables->mentioned() as $name) {
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

    /**
     * @throws MakefileErrorException
     */
    public function refresh(Target $target, VariableScope $scope): void
    {
        if ($target->isPhony || isset($this->state->paths[$target->name])) {
            return;
        }
        $this->locate($target->name, new VariableExpander($scope->context, $this->output, scope: $scope), $this->state);
        $path = $this->state->paths[$target->name] ?? $target->name;
        if ($path === $target->name || !isset($this->makefile->targetsByName[$path])) {
            return;
        }
        $this->state->targets[$target->name] = TargetAlias::merge(
            $target,
            $this->makefile->targetsByName[$path],
            $this->output,
        );
    }

    /**
     * @throws MakefileErrorException
     */
    public function resolve(string $name, VariableScope $scope): ?Target
    {
        if (array_key_exists($name, $this->state->targets)) {
            return $this->state->targets[$name];
        }
        $declared = $this->makefile->targetsByName[$name] ?? null;
        if ($declared?->isPhony === true) {
            return $this->state->targets[$name] = $declared;
        }
        $expander = new VariableExpander($scope->context, $this->output, scope: $scope);
        $this->locate($name, $expander, $this->state);
        $explicit =
            $this->makefile->targetsByName[$name]
            ?? $this->makefile->targetsByName[$this->state->paths[$name] ?? $name]
            ?? null;
        if (
            $explicit !== null
            && isset($this->state->paths[$name])
            && $this->state->paths[$name] !== $name
            && isset($this->makefile->targetsByName[$name], $this->makefile->targetsByName[$this->state->paths[$name]])
        ) {
            $explicit = TargetAlias::merge(
                $explicit,
                $this->makefile->targetsByName[$this->state->paths[$name]],
                $this->output,
            );
        }
        if ($explicit?->isPhony === true) {
            return $this->state->targets[$name] = $explicit;
        }
        $path = $this->state->paths[$name] ?? $name;
        $searchName = str_starts_with($name, '-l')
        || $this->makefile->searchPaths->retain($path)
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
                $this->compatible = $scope->context->reporting->enabled('i');
                $implicit = $this->implicit($searchName, $rule, $scope, [], $this->state, false);
                if ($implicit === null && $this->compatible) {
                    $implicit = $this->implicit($searchName, $rule, $scope, [], $this->state, true);
                }
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
        return $this->state->targets[$name] = $rules === [] ? null : new Target($searchName, $rules);
    }

    /**
     * @param array<int, true> $used
     *
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
            if ($dependency === '.WAIT') {
                continue;
            }
            if (!isset($branch->mentioned[$dependency]) && !in_array($dependency, $prerequisites->literal, true)) {
                $branch->intermediates[$dependency] = true;
            }
            $known =
                isset($this->makefile->targetsByName[$dependency])
                || in_array($dependency, $explicit->prerequisites->sequence ?? [], true);
            // Target-specific scopes are built only for dependencies that must be searched.
            $child = $known
                ? $scope
                : $this->makefile->targetVariables->scope($dependency, $scope->inherit(), $this->output);
            if (
                $known
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
            // Only a mentioned dependency can make the compatibility search succeed where this one failed.
            $this->compatible = $this->compatible || isset($branch->mentioned[$dependency]);
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
        }
        return true;
    }

    /**
     * @param array<int, true> $used
     *
     * @return list<ImplicitCandidate>
     */
    private function candidates(string $name, array $used): array
    {
        $key = $used === [] ? $name : $name . "\0" . implode(',', array_keys($used));
        return $this->candidates[$key] ??= $this->match($name, $used);
    }

    /**
     * @param array<int, true> $used
     *
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
        DebugTrace::write(
            $scope->context->reporting,
            $this->output,
            'i',
            "Looking for an implicit rule for '{$name}'.",
            count($used),
        );
        $candidates = $this->candidates($name, $used);
        $expander = new VariableExpander($scope->context, $this->output, scope: $scope);
        $expanded = [];
        foreach ([false, true] as $chain) {
            foreach ($candidates as $candidate) {
                if ($chain && $candidate->pattern->rule->doubleColon) {
                    continue;
                }
                DebugTrace::write(
                    $scope->context->reporting,
                    $this->output,
                    'i',
                    "Trying pattern rule with stem '{$candidate->stem}'.",
                    count($used),
                );
                $key = $candidate->index . ':' . $candidate->directory . ':' . $candidate->stem;
                $branch = clone $state;
                $expanded[$key] ??= [];
                $rule = $candidate->expand(
                    $name,
                    $explicit,
                    $expander,
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
                foreach ($candidate->pattern->names as $index => $patternName) {
                    $member = $rule->group[$index] ?? $name;
                    if ($this->makefile->special->contains('.PRECIOUS', $patternName)) {
                        $branch->precious[$member] = true;
                    }
                    if ($this->makefile->special->contains('.NOTINTERMEDIATE', $patternName)) {
                        $branch->notIntermediate[$member] = true;
                    }
                }
                $state = $branch;
                DebugTrace::write(
                    $scope->context->reporting,
                    $this->output,
                    'i',
                    "Found an implicit rule for '{$name}'.",
                    count($used),
                );
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

    /**
     * @throws MakefileErrorException
     */
    private function locate(string $name, VariableExpander $expander, SearchState $state): bool
    {
        if (isset($state->discardedPaths[$name]) && !$this->filesystem->exists($name)) {
            return false;
        }
        $path = $this->makefile->searchPaths->find($name, $this->filesystem, $expander, $this->makefile->targetsByName);
        $state->existed[$name] ??= $path !== null && $this->filesystem->exists($path);
        if ($path === null) {
            return false;
        }
        $state->paths[$name] = $path;
        return true;
    }

    /**
     * @param array<int, true> $used
     *
     * @return list<ImplicitCandidate>
     */
    private function match(string $name, array $used): array
    {
        $candidates = [];
        $specific = false;
        $member = ArchiveMember::parse($name);
        $base = basename($name);
        foreach ($this->makefile->patterns as $index => $pattern) {
            if ($pattern->rule->recipe === null && $pattern->rule->prerequisites->sequence !== []) {
                continue;
            }
            foreach ($pattern->names as $text) {
                $hasDirectory = str_contains($text, '/');
                $matchName = $member !== null && str_starts_with($text, '(') ? '(' . $member->member . ')' : $name;
                $stem = new Pattern($text)->match(
                    $hasDirectory ? $matchName : ($matchName === $name ? $base : basename($matchName)),
                );
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
                    $hasDirectory ? '' : substr($matchName, 0, strlen($matchName) - strlen(basename($matchName))),
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
}
