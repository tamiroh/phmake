<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function preg_replace_callback;
use function str_contains;
use function str_replace;

final class SecondaryExpansion
{
    /**
     * @throws MakefileErrorException
     */
    public static function expand(
        string $name,
        PrerequisiteExpression $expression,
        Prerequisites $previous,
        VariableExpander $expander,
        Filesystem $filesystem,
    ): Prerequisites {
        $expander = self::automatic($name, $expression, $previous, $expander, $filesystem);
        $text = $expression->stem === null ? $expression->text : self::substitute($expression->text, '$*');
        return DependencySyntax::parse(
            $expression->secondary ? $expander->expand(str_replace('\\:', ':', $text)) : $text,
            $filesystem,
        );
    }

    /**
     * @throws MakefileErrorException
     */
    public static function explicit(
        string $name,
        BuildRule $rule,
        VariableExpander $expander,
        Filesystem $filesystem,
    ): BuildRule {
        $expressions = $rule->prerequisites->expressions;
        if ($expressions === []) {
            return $rule;
        }
        $parts = [];
        $previous = new Prerequisites();
        $pending = [];
        foreach ($expressions as $index => $expression) {
            if (!$expression->secondary || !str_contains($expression->text, '$')) {
                $parts[$index] = $expression->initial ?? self::expand(
                    $name,
                    $expression,
                    $previous,
                    $expander,
                    $filesystem,
                );
                $previous = $previous->merge($parts[$index]);
            } else {
                $pending[] = [$index, $expression];
            }
        }
        foreach ($pending as [$index, $expression]) {
            $parts[$index] = self::expand($name, $expression, $previous, $expander, $filesystem);
            $previous = $previous->merge($parts[$index]);
        }
        $prerequisites = new Prerequisites();
        foreach ($expressions as $index => $_) {
            $prerequisites = $prerequisites->merge($parts[$index]);
        }
        return new BuildRule(
            $prerequisites,
            $rule->recipe,
            $rule->doubleColon,
            $rule->stem,
            $rule->group,
            $rule->firstPrerequisite,
        );
    }

    /**
     * Expand only the words reached by the current search pass, reusing earlier word expansions.
     *
     * @param array<int, string> $expanded
     *
     * @throws MakefileErrorException
     *
     * @return iterable<Prerequisites>
     */
    public static function implicitParts(
        string $name,
        PrerequisiteExpression $expression,
        Prerequisites $previous,
        VariableExpander $expander,
        Filesystem $filesystem,
        string $directory,
        array &$expanded,
    ): iterable {
        $expander = self::automatic($name, $expression, $previous, $expander, $filesystem, $directory);
        $orderOnly = false;
        foreach (DependencySyntax::expressions($expression->text) as $index => $word) {
            $text =
                $expanded[$index] ??= $expander->expand(self::substitute($word, $directory === '' ? '$*' : '$(*F)'));
            $part = DependencySyntax::parse(
                ($orderOnly ? '| ' : '') . $text,
                $filesystem,
                new Pattern($word)->hasWildcard() ? $directory : '',
            );
            yield new Prerequisites(
                $part->normal,
                $part->orderOnly,
                literal: new Pattern($word)->hasWildcard() ? [] : $part->sequence,
            );
            $orderOnly = $orderOnly || DependencySyntax::delimiter($text, '|') !== null;
        }
    }

    private static function automatic(
        string $name,
        PrerequisiteExpression $expression,
        Prerequisites $previous,
        VariableExpander $expander,
        Filesystem $filesystem,
        string $directory = '',
    ): VariableExpander {
        $variables = AutomaticVariables::forRule(
            $name,
            new BuildRule($previous, stem: $expression->stem === null ? '' : $directory . $expression->stem),
            null,
            [],
            $filesystem,
        );
        foreach (['?', '?D', '?F'] as $variable) {
            $variables[] = new Variable($variable, '', false, 'automatic');
        }
        return $expander->withVariables($variables)->atSource($expression->source);
    }

    private static function substitute(string $text, string $stem): string
    {
        return (
            preg_replace_callback(
                '/\\S+/',
                static fn(array $match): string => new Pattern($match[0])->substitute($stem),
                $text,
            ) ?? $text
        );
    }
}
