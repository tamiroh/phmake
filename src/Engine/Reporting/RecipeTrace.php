<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Reporting;

use Tamiroh\Phmake\Engine\Execution\Files\BuildFiles;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\BuildRule;
use Tamiroh\Phmake\Engine\Rule\Target;

use function array_unique;
use function implode;

/**
 * Explain a recipe invocation using its source and automatic prerequisite values.
 *
 * @internal
 */
final class RecipeTrace
{
    /**
     * @throws MakefileErrorException
     */
    public static function write(
        Target $target,
        BuildRule $rule,
        ?int $modifiedAt,
        VariableExpander $expander,
        BuildFiles $files,
        Output $output,
    ): void {
        if ($target->isPhony) {
            $reason = 'target is .PHONY';
        } elseif ($modifiedAt === null) {
            $reason = 'target does not exist';
        } else {
            $reason = $expander->expand('$?');
            if ($reason === '') {
                $missing = [];
                foreach (array_unique([...$rule->prerequisites->normal, ...$rule->prerequisites->orderOnly]) as $name) {
                    if ($files->time($name) === null) {
                        $missing[] = $files->path($name);
                    }
                }
                $reason = $missing === [] ? 'unknown reasons' : implode(' ', $missing);
            }
        }
        $output->write(
            ($rule->recipe->source ?? '<builtin>') . ": update target '{$target->name}' due to: {$reason}\n",
        );
    }
}
