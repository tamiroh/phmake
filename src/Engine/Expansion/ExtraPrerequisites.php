<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Expansion;

use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\DependencySyntax;
use Tamiroh\Phmake\Engine\Rule\Prerequisites;

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
        EvaluationContext $context,
        Filesystem $filesystem,
        Output $output,
    ): Prerequisites {
        $local = $makefile->targetVariables->definitionsFor($name);
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
