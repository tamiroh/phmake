<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Makefile\Variable;

use function array_values;
use function in_array;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

final class MakefileBuilder
{
    /** @var array<string, Target> */
    private array $targets = [];

    /** @var array<string, string> */
    private array $phonyNames = [];

    /** @var array<string, true> */
    private array $recipes = [];

    /** @var list<Target> */
    private array $patterns = [];

    private ?string $defaultGoal = null;

    /** @var list<string> */
    private array $suffixes = ['.c', '.o'];

    public function addRule(Rule $rule): void
    {
        foreach ($rule->targetNames as $name) {
            if ($name === '.SUFFIXES') {
                $this->suffixes = $rule->dependencyNames === [] ? [] : [...$this->suffixes, ...$rule->dependencyNames];
                continue;
            }
            if (str_contains($name, '%')) {
                $this->patterns[] = new Target($name, $rule->dependencyNames, $rule->commands, false);
                continue;
            }
            if ($name === '.PHONY') {
                foreach ($rule->dependencyNames as $dependency) {
                    $this->phonyNames[$dependency] = $dependency;
                }
                continue;
            }

            if ($this->defaultGoal === null && (!str_starts_with($name, '.') || str_contains($name, '/'))) {
                $this->defaultGoal = $name;
            }

            if ($rule->hasRecipe) {
                if (isset($this->recipes[$name])) {
                    throw new ParseException(
                        $rule->lineNumber,
                        "Multiple recipes for target `$name' are not supported",
                    );
                }
                $this->recipes[$name] = true;
            }
            $previous = $this->targets[$name] ?? null;
            $this->targets[$name] = new Target(
                $name,
                $rule->hasRecipe
                    ? [...$rule->dependencyNames, ...($previous->dependencies ?? [])]
                    : [...($previous->dependencies ?? []), ...$rule->dependencyNames],
                $rule->hasRecipe ? $rule->commands : $previous->commands ?? [],
                false,
                hasRecipe: $rule->hasRecipe || ($previous->hasRecipe ?? false),
            );
        }
    }

    /**
     * @param list<Variable> $variables
     * @param list<Target> $builtinRules
     */
    public function build(array $variables, array $builtinRules = []): Makefile
    {
        foreach ($this->phonyNames as $name) {
            $previous = $this->targets[$name] ?? null;
            $this->targets[$name] = new Target($name, $previous->dependencies ?? [], $previous->commands ?? [], true);
        }

        $patterns = $this->patterns;
        foreach ($this->targets as $target) {
            $pattern = $this->suffixPattern($target);
            if ($pattern !== null) {
                $patterns[] = $pattern;
            }
        }
        if (in_array('.c', $this->suffixes, strict: true) && in_array('.o', $this->suffixes, strict: true)) {
            foreach ($builtinRules as $builtin) {
                foreach ($patterns as $pattern) {
                    if ($pattern->name === $builtin->name && $pattern->dependencies === $builtin->dependencies) {
                        continue 2;
                    }
                }
                $patterns[] = $builtin;
            }
        }
        return new Makefile(array_values($this->targets), $variables, $this->defaultGoal, $patterns);
    }

    private function suffixPattern(Target $target): ?Target
    {
        if ($target->dependencies !== [] || $target->isPhony) {
            return null;
        }
        foreach ($this->suffixes as $source) {
            if (!str_starts_with($target->name, $source)) {
                continue;
            }
            $destination = substr($target->name, strlen($source));
            if ($destination === '' || in_array($destination, $this->suffixes, strict: true)) {
                return new Target('%' . $destination, ['%' . $source], $target->commands, false);
            }
        }
        return null;
    }
}
