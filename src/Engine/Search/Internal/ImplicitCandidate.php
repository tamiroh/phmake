<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Search\Internal;

use Closure;
use Tamiroh\Phmake\Engine\Expansion\SecondaryExpansion;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\BuildRule;
use Tamiroh\Phmake\Engine\Rule\FileName;
use Tamiroh\Phmake\Engine\Rule\Pattern;
use Tamiroh\Phmake\Engine\Rule\PatternRule;
use Tamiroh\Phmake\Engine\Rule\PrerequisiteExpression;
use Tamiroh\Phmake\Engine\Rule\Prerequisites;

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
        $pattern = $this->pattern->rule->prerequisites;
        $literal = [];
        foreach ($pattern->sequence as $prerequisite) {
            if (!Pattern::of($prerequisite)->hasWildcard()) {
                $literal[] = $prerequisite;
            }
        }
        // Most candidates are rejected, so the other lists are substituted only for an accepted one.
        $sequence = $this->substitute($pattern->sequence, $filesystem);
        $prerequisites = new Prerequisites(sequence: $sequence, literal: $literal);
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
        if (!$secondary) {
            if (!$accept($prerequisites)) {
                return null;
            }
            $prerequisites = new Prerequisites(
                $this->substitute($pattern->normal, $filesystem),
                $this->substitute($pattern->orderOnly, $filesystem),
                sequence: $sequence,
                literal: $literal,
            );
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
            $pattern = Pattern::of($name);
            $name = FileName::normalize(
                ($pattern->hasWildcard() ? $this->directory : '') . $pattern->substitute($this->stem),
            );
            $paths = strpbrk($name, '*?[') === false ? [] : $filesystem?->matching($name) ?? [];
            $result = [...$result, ...($paths === [] ? [$name] : $paths)];
        }
        return $result;
    }
}
