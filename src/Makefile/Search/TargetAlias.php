<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Search;

use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\Target;

/**
 * Merge rule declarations when directory search identifies the same file.
 *
 * @internal
 */
final class TargetAlias
{
    public static function merge(Target $alias, Target $canonical, Output $output): Target
    {
        $left = $canonical->rules[0] ?? new BuildRule();
        $right = $alias->rules[0] ?? new BuildRule();
        if ($left->recipe !== null && $right->recipe !== null && $left->recipe !== $right->recipe) {
            $source = $right->recipe->source;
            $output->writeWarning(
                "Recipe was specified for file '{$alias->name}' at " . ($source ?? '<builtin>') . ',',
                $source,
            );
            $output->writeWarning(
                "but '{$alias->name}' is now considered the same file as '{$canonical->name}'.",
                $source,
            );
            $output->writeWarning(
                "Recipe for '{$alias->name}' will be ignored in favor of the one for '{$canonical->name}'.",
                $source,
            );
        }
        if ($left->doubleColon || $right->doubleColon) {
            return new Target(
                $alias->name,
                [...$canonical->rules, ...$alias->rules],
                $alias->isPhony || $canonical->isPhony,
            );
        }
        $selected = $left->recipe === null ? $right : $left;
        return new Target(
            $alias->name,
            [new BuildRule(
                $left->prerequisites->merge($right->prerequisites),
                $selected->recipe,
                false,
                $selected->stem,
                $selected->group,
                $selected->firstPrerequisite,
                $selected->implicit,
            )],
            $alias->isPhony || $canonical->isPhony,
        );
    }
}
