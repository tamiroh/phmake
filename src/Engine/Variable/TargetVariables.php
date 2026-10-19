<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Variable;

use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\Pattern;

use function array_replace;
use function array_values;
use function in_array;
use function strlen;
use function usort;

/**
 * Definitions are evaluated while reading; matching and inheritance happen while building.
 */
final class TargetVariables
{
    /** @var array<array-key, VariableSet> */
    private array $definitions = [];

    /** @var list<PatternAssignment> */
    private array $patterns = [];

    /** @var array<string, VariableSet>|null */
    private ?array $exact = null;

    /** @var array<string, VariableSet> */
    private array $installedPatterns = [];

    public function alias(string $name, string $canonical): void
    {
        $this->exact ??= $this->exactDefinitions();
        $set = $this->exact[$canonical] ??= new VariableSet();
        $set->definitions = array_replace($this->exact[$name]->definitions ?? [], $set->definitions);
        $this->exact[$name] = $set;
    }

    /**
     * @throws MakefileErrorException
     */
    public function define(
        string $target,
        Assignment $assignment,
        VariableExpander $expander,
        string $origin,
        bool $private,
        ?bool $export,
        ?Output $output,
    ): void {
        $targetPattern = new Pattern($target);
        $pattern = $targetPattern->hasWildcard();
        $variables = $this->definitions[$target]->definitions ?? [];
        $scope = $pattern ? $expander : $this->definitionScope($variables, $expander);
        $assignment = $assignment->resolveName($scope);
        if ($pattern) {
            $evaluated = in_array($assignment->operator, [':=', '::='], true)
                ? $scope->expand($assignment->expression)
                : null;
            $global = $expander->context->variables[$assignment->name] ?? null;
            if (
                $origin !== 'override'
                && $global !== null
                && in_array($global->origin, ['command line', 'environment override'], true)
            ) {
                $assignment = new Assignment($assignment->name, $assignment->operator, $global->expression);
                $origin = $global->origin;
                $evaluated = $evaluated === null ? null : $global->expression;
            }
            $this->patterns[] = new PatternAssignment(
                $targetPattern,
                $assignment,
                $origin,
                $private,
                $export,
                $expander->source,
                $evaluated,
            );
            return;
        }
        $set = $this->definitions[$target] ??= new VariableSet();
        $this->exact = null;
        // A conditional definition can find an existing global binding. Modifiers
        // apply to that binding even though no target-local value is created.
        if ($assignment->operator === '?=' && !isset($variables[$assignment->name])) {
            $global = $expander->context->variables[$assignment->name] ?? null;
            if ($global !== null) {
                $expander->context->variables[$assignment->name] = $global->withAttributes(
                    $private,
                    $export ?? $global->export,
                );
                return;
            }
        }
        $value = $assignment->apply($variables, $origin, $output, $expander->source, $scope, target: true);
        $global = $expander->context->variables[$assignment->name] ?? null;
        if (
            $value->origin !== 'override'
            && $global !== null
            && in_array($global->origin, ['command line', 'environment override'], true)
        ) {
            // Command-line precedence replaces the value, not the local modifiers.
            $value = new Variable(
                $value->name,
                $global->expression,
                $global->recursive,
                $global->origin,
                $value->source,
                $value->private,
                $value->export,
            );
        }
        $variables[$assignment->name] = $value->withAttributes($private, $export ?? $value->export);
        $set->definitions = $variables;
    }

    /**
     * @return array<string, Variable>|null
     */
    public function definitionsFor(string $name): ?array
    {
        return $this->definitions[$name]->definitions ?? null;
    }

    /**
     * @return list<string>
     */
    public function mentioned(): array
    {
        $names = [];
        foreach ($this->definitions as $name => $_) {
            if (!new Pattern((string) $name)->hasWildcard()) {
                $names[] = (string) $name;
            }
        }
        return $names;
    }

    /**
     * @throws MakefileErrorException
     */
    public function scope(string $name, VariableScope $parent, ?Output $output = null): VariableScope
    {
        if (!isset($this->installedPatterns[$name])) {
            $patterns = [];
            foreach ($this->patterns as $definition) {
                $stem = $definition->pattern->match($name);
                if ($stem !== null) {
                    $patterns[] = [strlen($stem), $definition];
                }
            }
            usort($patterns, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
            $set = new VariableSet();
            $this->installedPatterns[$name] = $set;
            foreach ($patterns as [, $definition]) {
                $scope = $parent->withSet($set);
                $expander = new VariableExpander($parent->context, $output, scope: $scope);
                $assignment = $definition->assignment;
                if (
                    $assignment->operator === '?='
                    && $scope->setAttributes($assignment->name, $definition->private, $definition->export)
                ) {
                    continue;
                }
                $value = $assignment->apply(
                    $set->definitions,
                    $definition->origin,
                    $output,
                    $definition->source,
                    $expander,
                    target: true,
                    evaluated: $definition->evaluated,
                );
                $set->definitions[$assignment->name] = $value->withAttributes(
                    $definition->private,
                    $definition->export ?? $value->export,
                );
            }
        }
        $parent = $parent->withSet($this->installedPatterns[$name]);
        $this->exact ??= $this->exactDefinitions();
        $this->exact[$name] ??= new VariableSet();
        return $parent->withSet($this->exact[$name]);
    }

    /**
     * @param array<string, Variable> $variables
     */
    private function definitionScope(array $variables, VariableExpander $expander): VariableExpander
    {
        foreach ($variables as $variable) {
            $global = $expander->variable($variable->name);
            if (
                $global !== null
                && $variable->origin !== 'override'
                && in_array($global->origin, ['command line', 'environment override'], true)
            ) {
                $variables[$variable->name] = $global;
            }
        }
        return $expander->withVariables(array_values($variables));
    }

    /**
     * Index definitions without wildcards by target name; the last matching definition wins.
     *
     * @return array<string, VariableSet>
     */
    private function exactDefinitions(): array
    {
        $exact = [];
        foreach ($this->definitions as $text => $variables) {
            $pattern = new Pattern((string) $text);
            if (!$pattern->hasWildcard()) {
                $exact[$pattern->substitute('%')] = $variables;
            }
        }
        return $exact;
    }
}
