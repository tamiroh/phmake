<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\BuildRule;
use Tamiroh\Phmake\Makefile\EvaluationContext;
use Tamiroh\Phmake\Makefile\Exports;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\Output;
use Tamiroh\Phmake\Makefile\Pattern;
use Tamiroh\Phmake\Makefile\PatternRule;
use Tamiroh\Phmake\Makefile\PrerequisiteExpression;
use Tamiroh\Phmake\Makefile\Prerequisites;
use Tamiroh\Phmake\Makefile\Recipe;
use Tamiroh\Phmake\Makefile\SearchPaths;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Makefile\TargetVariables;
use Tamiroh\Phmake\Makefile\Variable;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

final class MakefileBuilder
{
    /** @var array<string, Target> */
    private array $targets = [];

    /** @var array<string, string> */
    private array $phonyNames = [];

    /** @var list<PatternRule> */
    private array $patterns = [];

    private ?string $defaultGoal = null;

    /** @var list<string> */
    private array $suffixes = [];

    private bool $secondary = false;

    public readonly TargetVariables $scopes;

    public readonly SearchPaths $paths;

    public function __construct(
        private bool $builtinSuffixes = true,
        private ?Output $output = null,
    ) {
        $this->scopes = new TargetVariables();
        $this->paths = new SearchPaths();
    }

    /** @throws ParseException */
    public function addRule(Rule $rule): void
    {
        if (in_array('.SECONDEXPANSION', $rule->targetNames, true)) {
            $this->secondary = true;
        }
        if ($rule->targetNames === []) {
            return;
        }
        if ($rule->grouped && !$rule->hasRecipe) {
            throw new ParseException($rule->lineNumber, 'grouped targets must provide a recipe');
        }
        $recipe = $rule->hasRecipe ? new Recipe($rule->commands, $rule->commands[0]->source ?? $rule->source) : null;
        if ($rule->targetPattern === null && new Pattern($rule->targetNames[0])->hasWildcard()) {
            $this->patterns = array_values(array_filter(
                $this->patterns,
                static fn(PatternRule $pattern): bool => (
                    $pattern->names !== $rule->targetNames
                    || $pattern->rule->prerequisites->normal !== $rule->prerequisites->normal
                    || $pattern->rule->prerequisites->orderOnly !== $rule->prerequisites->orderOnly
                ),
            ));
            $this->patterns[] = new PatternRule(
                $rule->targetNames,
                new BuildRule(
                    new Prerequisites(
                        $rule->prerequisites->normal,
                        $rule->prerequisites->orderOnly,
                        array_map(
                            fn(PrerequisiteExpression $expression): PrerequisiteExpression => new PrerequisiteExpression(
                                $expression->text,
                                null,
                                $rule->hasRecipe,
                                $expression->source,
                                $this->secondary,
                            ),
                            $rule->prerequisites->expressions,
                        ),
                    ),
                    $recipe,
                    $rule->doubleColon,
                ),
            );
            return;
        }
        foreach ($rule->targetNames as $rawName) {
            $name = new Pattern($rawName)->substitute('%');
            if ($name === '.SUFFIXES') {
                if ($rule->prerequisites->normal === []) {
                    $this->builtinSuffixes = false;
                }
                $this->suffixes = $rule->prerequisites->normal === []
                    ? []
                    : [...$this->suffixes, ...$rule->prerequisites->normal];
                continue;
            }
            if ($name === '.PHONY') {
                foreach ($rule->prerequisites->normal as $dependency) {
                    $this->phonyNames[$dependency] = $dependency;
                }
                continue;
            }
            if ($this->defaultGoal === null && (!str_starts_with($name, '.') || str_contains($name, '/'))) {
                $this->defaultGoal = $name;
            }
            $stem = $rule->targetPattern === null ? '' : new Pattern($rule->targetPattern)->match($name);
            if ($stem === null) {
                $this->output?->writeWarning("target '$name' doesn't match the target pattern", $rule->source);
            }
            $prerequisites =
                $rule->targetPattern === null || $stem === null
                    ? $rule->prerequisites
                    : new Prerequisites(
                        $this->substitute($rule->prerequisites->normal, $stem),
                        $this->substitute($rule->prerequisites->orderOnly, $stem),
                        $rule->prerequisites->expressions,
                    );
            $prerequisites = new Prerequisites(
                $prerequisites->normal,
                $prerequisites->orderOnly,
                array_map(
                    fn(PrerequisiteExpression $expression): PrerequisiteExpression => new PrerequisiteExpression(
                        $expression->text,
                        $rule->targetPattern === null ? null : $stem,
                        $rule->hasRecipe,
                        $expression->source,
                        $this->secondary,
                        new Prerequisites($prerequisites->normal, $prerequisites->orderOnly),
                    ),
                    $prerequisites->expressions,
                ),
            );
            $this->addTarget(
                $name,
                new BuildRule(
                    $prerequisites,
                    $recipe,
                    $rule->doubleColon,
                    $stem ?? '',
                    $rule->grouped
                        ? array_map(static fn(string $name): string => new Pattern($name)->substitute(
                            '%',
                        ), $rule->targetNames) : [],
                ),
                $rule,
            );
        }
    }

    /**
     * @param list<Variable> $variables
     * @param list<PatternRule> $builtinRules
     */
    public function build(
        array $variables,
        array $builtinRules = [],
        Exports $exports = new Exports(),
        bool $builtinSuffixes = true,
        ?EvaluationContext $context = null,
    ): Makefile {
        if ($builtinSuffixes && $this->builtinSuffixes) {
            $this->suffixes = ['.o', '.c', '.f', ...$this->suffixes];
        }
        foreach ($this->phonyNames as $name) {
            $this->targets[$name] = new Target($name, $this->targets[$name]->rules ?? [new BuildRule()], true);
        }
        foreach ($this->targets as $target) {
            $this->targets[$target->name] = new Target(
                $target->name,
                array_map(fn(BuildRule $rule): BuildRule => $this->explicitStem($target->name, $rule), $target->rules),
                $target->isPhony,
            );
        }
        $patterns = $this->patterns;
        foreach ($this->targets as $target) {
            $pattern = $this->suffixPattern($target);
            if ($pattern !== null) {
                $patterns[] = $pattern;
            }
        }
        foreach ($builtinRules as $builtin) {
            if ($this->builtinEnabled($builtin)) {
                foreach ($patterns as $pattern) {
                    if (
                        $pattern->names === $builtin->names
                        && $pattern->rule->prerequisites->normal === $builtin->rule->prerequisites->normal
                    ) {
                        continue 2;
                    }
                }
                $patterns[] = $builtin;
            }
        }
        return new Makefile(
            array_values($this->targets),
            $variables,
            $this->defaultGoal,
            $patterns,
            $exports,
            $context,
            $this->scopes,
            $this->paths,
        );
    }

    /** @throws ParseException */
    private function addTarget(string $name, BuildRule $rule, Rule $declaration): void
    {
        $previous = $this->targets[$name]->rules[0] ?? null;
        if ($previous !== null && $previous->doubleColon !== $rule->doubleColon) {
            throw new ParseException($declaration->lineNumber, "target file '$name' has both : and :: entries");
        }
        if ($previous === null || $rule->doubleColon) {
            $this->targets[$name] = new Target($name, [...($this->targets[$name]->rules ?? []), $rule]);
            return;
        }
        if ($rule->recipe !== null && $previous->recipe !== null) {
            $this->output?->writeWarning("warning: overriding recipe for target '$name'", $rule->recipe->source);
            $this->output?->writeWarning("warning: ignoring old recipe for target '$name'", $previous->recipe->source);
        }
        $this->targets[$name] = new Target($name, [new BuildRule(
            $rule->recipe === null
                ? $previous->prerequisites->merge($rule->prerequisites)
                : $rule->prerequisites->merge($previous->prerequisites),
            $rule->recipe ?? $previous->recipe,
            false,
            $declaration->targetPattern === null ? $previous->stem : $rule->stem,
            $rule->recipe === null ? $previous->group : $rule->group,
        )]);
    }

    private function builtinEnabled(PatternRule $pattern): bool
    {
        foreach ([...$pattern->names, ...$pattern->rule->prerequisites->normal] as $name) {
            $suffix = substr($name, 1);
            if ($suffix !== '' && !in_array($suffix, $this->suffixes, true)) {
                return false;
            }
        }
        return true;
    }

    private function explicitStem(string $name, BuildRule $rule): BuildRule
    {
        if ($rule->stem !== '') {
            return $rule;
        }
        foreach ($rule->prerequisites->expressions as $expression) {
            if ($expression->stem !== null) {
                return $rule;
            }
        }
        foreach ($this->suffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return new BuildRule(
                    $rule->prerequisites,
                    $rule->recipe,
                    $rule->doubleColon,
                    substr($name, 0, -strlen($suffix)),
                    $rule->group,
                );
            }
        }
        return $rule;
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private function substitute(array $names, string $stem): array
    {
        $result = [];
        foreach ($names as $name) {
            $name = new Pattern($name)->substitute($stem);
            if ($name !== '') {
                $result[] = $name;
            }
        }
        return $result;
    }

    private function suffixPattern(Target $target): ?PatternRule
    {
        $rule = $target->rules[0] ?? null;
        if ($rule === null || $target->isPhony) {
            return null;
        }
        foreach ($this->suffixes as $source) {
            if (!str_starts_with($target->name, $source)) {
                continue;
            }
            $destination = substr($target->name, strlen($source));
            if ($destination === '' || in_array($destination, $this->suffixes, true)) {
                if ($rule->prerequisites->sequence !== []) {
                    if (isset($this->targets['.POSIX'])) {
                        return null;
                    }
                    $this->output?->writeWarning(
                        'warning: ignoring prerequisites on suffix rule definition',
                        $rule->recipe?->source,
                    );
                }
                return new PatternRule(
                    ['%' . $destination],
                    new BuildRule(new Prerequisites(['%' . $source]), $rule->recipe),
                );
            }
        }
        return null;
    }
}
