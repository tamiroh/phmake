<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_values;
use function in_array;
use function strlen;
use function usort;

/** Definitions are evaluated while reading; matching and inheritance happen while building. */
final class TargetVariables
{
    /** @var array<array-key, array<string, Variable>> */
    private array $definitions = [];
    /** @var list<array{string, Variable}> */
    private array $patterns = [];

    /** @throws MakefileErrorException */
    public function define(
        string $target,
        Assignment $assignment,
        VariableExpander $expander,
        string $origin,
        bool $private,
        ?bool $export,
        ?Output $output,
    ): void {
        $pattern = new Pattern($target)->hasWildcard();
        $variables = $this->definitions[$target] ?? [];
        $scope = $pattern ? $expander : $this->definitionScope($variables, $expander);
        $assignment = $assignment->resolveName($scope);
        if ($assignment->operator === '?=' && !$pattern && $scope->variable($assignment->name) !== null) {
            return;
        }
        $previous = $variables[$assignment->name] ?? null;
        if ($pattern && $assignment->operator === '+=') {
            if ($previous?->origin === 'override' && $origin !== 'override') {
                return;
            }
            // Pattern appends are evaluated when the pattern is installed on a target.
            $variables[$assignment->name] = new Variable(
                $assignment->name,
                $assignment->expression,
                $previous->recursive ?? true,
                $origin,
                $expander->source,
            );
        } else {
            $assignment->apply($variables, $origin, $output, $expander->source, $scope);
        }
        $value = $variables[$assignment->name];
        if ($value === $previous) {
            return;
        }
        $variables[$assignment->name] = new Variable(
            $value->name,
            $value->expression,
            $value->recursive,
            $value->origin,
            $value->source,
            $private,
            $export ?? $previous?->export,
            $assignment->operator === '+=' && ($pattern || $previous === null || $previous->append),
            $pattern && $assignment->operator === '?=',
        );
        $this->definitions[$target] = $variables;
        if ($pattern) {
            $this->patterns[] = [$target, $variables[$assignment->name]];
        }
    }

    /** @return list<string> */
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

    /** @throws MakefileErrorException */
    public function scope(string $name, VariableScope $parent, ?Output $output = null): VariableScope
    {
        $patterns = [];
        $exact = [];
        foreach ($this->definitions as $text => $variables) {
            $pattern = new Pattern((string) $text);
            if (!$pattern->hasWildcard() && $pattern->substitute('%') === $name) {
                $exact = $variables;
            }
        }
        foreach ($this->patterns as [$text, $variable]) {
            $stem = new Pattern($text)->match($name);
            if ($stem !== null) {
                $patterns[] = [strlen($stem), $variable];
            }
        }
        usort($patterns, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($patterns as [, $variable]) {
            if ($variable->append && !$variable->recursive) {
                $variable = new Variable(
                    $variable->name,
                    new VariableExpander($parent->context, $output, scope: $parent)->expand($variable->expression),
                    false,
                    $variable->origin,
                    $variable->source,
                    $variable->private,
                    $variable->export,
                    true,
                );
            }
            $parent = $parent->with([$variable->name => $variable]);
        }
        return $parent->with($exact);
    }

    /** @param array<string, Variable> $variables */
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
}
