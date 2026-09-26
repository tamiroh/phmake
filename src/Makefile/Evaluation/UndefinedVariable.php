<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use function in_array;

/**
 * Warn about missing references, excluding GNU make's implicitly defined names.
 *
 * @internal
 */
final class UndefinedVariable
{
    public static function warn(VariableExpander $expander, string $name): void
    {
        if (
            $expander->context->reporting->warnUndefinedVariables
            && !in_array(
                $name,
                [
                    'MAKECMDGOALS',
                    'MAKE_RESTARTS',
                    'MAKE_TERMOUT',
                    'MAKE_TERMERR',
                    'MAKEOVERRIDES',
                    '.DEFAULT',
                    '-*-command-variables-*-',
                    '-*-eval-flags-*-',
                    'VPATH',
                    'GPATH',
                ],
                true,
            )
        ) {
            $expander->output?->writeWarning("warning: undefined variable '{$name}'", $expander->source);
        }
    }
}
