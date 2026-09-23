<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Makefile\Variable;

use function array_values;
use function str_contains;
use function str_starts_with;

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

    public function addRule(Rule $rule): void
    {
        foreach ($rule->targetNames as $name) {
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

    /** @param list<Variable> $variables */
    public function build(array $variables): Makefile
    {
        foreach ($this->phonyNames as $name) {
            $previous = $this->targets[$name] ?? null;
            $this->targets[$name] = new Target($name, $previous->dependencies ?? [], $previous->commands ?? [], true);
        }

        return new Makefile(array_values($this->targets), $variables, $this->defaultGoal, $this->patterns);
    }
}
