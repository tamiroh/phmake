<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\DependencySyntax;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;

use function in_array;

/**
 * Dependency-only prerequisites use a target's own definition, without inheritance.
 *
 * @internal
 */
final class ExtraPrerequisites
{
    /**
     * @throws MakefileErrorException
     */
    public static function forTarget(
        string $name,
        Makefile $makefile,
        Filesystem $filesystem,
        Output $output,
    ): Prerequisites {
        $local = $makefile->scopes->definitionsFor($name);
        $context = $makefile->context ?? new EvaluationContext($makefile->variables);
        $variable = $local === null ? $context->variables['.EXTRA_PREREQS'] ?? null : $local['.EXTRA_PREREQS'] ?? null;
        if ($variable === null) {
            return new Prerequisites();
        }
        $prerequisites = DependencySyntax::parse(
            new VariableExpander($context, $output)->expand($variable->expression),
            $filesystem,
        );
        if (in_array($name, $prerequisites->sequence, true)) {
            return new Prerequisites();
        }
        return new Prerequisites(extra: $prerequisites->normal);
    }
}
