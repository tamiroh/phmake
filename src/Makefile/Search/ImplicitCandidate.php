<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Search;

use Closure;
use Tamiroh\Phmake\Makefile\Evaluation\SecondaryExpansion;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\FileName;
use Tamiroh\Phmake\Makefile\Rule\Pattern;
use Tamiroh\Phmake\Makefile\Rule\PatternRule;
use Tamiroh\Phmake\Makefile\Rule\PrerequisiteExpression;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;

use function array_filter;
use function array_values;
use function count;
use function strpbrk;

/**
 * A matched pattern retains the unexpanded rule until its prerequisites are tested.
 *
 * @internal
 */
final readonly class ImplicitCandidate
{
    public function __construct(
        public PatternRule $pattern,
        public int $index,
        public string $stem,
        public string $directory,
        public bool $matchAnything,
    ) {}

    /**
     * @param Closure(Prerequisites): bool $accept
     * @param array<int, array<int, string>> $expanded
     *
     * @throws MakefileErrorException
     */
    public function expand(
        string $name,
        ?BuildRule $explicit,
        VariableExpander $expander,
        Filesystem $filesystem,
        Closure $accept,
        array &$expanded,
    ): ?BuildRule {
        $prerequisites = new Prerequisites(
            $this->substitute($this->pattern->rule->prerequisites->normal, $filesystem),
            $this->substitute($this->pattern->rule->prerequisites->orderOnly, $filesystem),
            sequence: $this->substitute($this->pattern->rule->prerequisites->sequence, $filesystem),
            literal: array_values(array_filter(
                $this->pattern->rule->prerequisites->sequence,
                static fn(string $name): bool => !new Pattern($name)->hasWildcard(),
            )),
        );
        $secondary = false;
        foreach ($this->pattern->rule->prerequisites->expressions as $index => $expression) {
            if (!$expression->secondary) {
                continue;
            }
            $secondary = true;
            $prerequisites = new Prerequisites();
            $expanded[$index] ??= [];
            foreach (SecondaryExpansion::implicitParts(
                $name,
                new PrerequisiteExpression(
                    $expression->text,
                    $this->stem,
                    $expression->hasRecipe,
                    $expression->source,
                    true,
                ),
                $explicit->prerequisites ?? new Prerequisites(),
                $expander,
                $filesystem,
                $this->directory,
                $expanded[$index],
            ) as $part) {
                if (!$accept($part)) {
                    return null;
                }
                $prerequisites = $prerequisites->merge($part);
            }
        }
        if (!$secondary && !$accept($prerequisites)) {
            return null;
        }
        return new BuildRule(
            $prerequisites,
            $this->pattern->rule->recipe,
            $explicit->doubleColon ?? false,
            $this->directory . $this->stem,
            count($this->pattern->names) > 1 ? $this->substitute($this->pattern->names) : [],
            implicit: true,
        );
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function substitute(array $names, ?Filesystem $filesystem = null): array
    {
        $result = [];
        foreach ($names as $name) {
            $pattern = new Pattern($name);
            $name = FileName::normalize(
                ($pattern->hasWildcard() ? $this->directory : '') . $pattern->substitute($this->stem),
            );
            $paths = strpbrk($name, '*?[') === false ? [] : $filesystem?->matching($name) ?? [];
            $result = [...$result, ...($paths === [] ? [$name] : $paths)];
        }
        return $result;
    }
}
