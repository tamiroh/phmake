<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Invocation;

use Tamiroh\Phmake\Engine\Execution\ExecutionOptions;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Variable\Assignment;
use Tamiroh\Phmake\Engine\Variable\Variable;

use function array_map;
use function implode;
use function ltrim;
use function str_replace;
use function str_starts_with;

/**
 * Serialize invocation options without replacing higher-priority flag definitions.
 */
final class MakeFlags
{
    /**
     * @param array<string, Variable> $variables
     *
     * @throws MakefileErrorException
     */
    public static function define(
        InvocationOptions $options,
        ExecutionOptions $execution,
        array &$variables,
        bool $posix = false,
        ?int $makefileRestart = null,
    ): void {
        $execution = $makefileRestart === null ? $execution : $execution->forMakefiles($makefileRestart);
        new Assignment('MFLAGS', '=', self::expression($options, $execution, legacy: true))->apply(
            $variables,
            $options->environmentOverrides ? 'environment override' : 'environment',
        );
        new Assignment(
            'MAKEFLAGS',
            '=',
            self::expression($options, $execution, variables: $variables, posix: $posix),
        )->apply($variables, $options->environmentOverrides ? 'environment override' : 'file');
    }

    /**
     * @param array<string, Variable> $variables
     */
    private static function expression(
        InvocationOptions $options,
        ExecutionOptions $execution,
        bool $legacy = false,
        array $variables = [],
        bool $posix = false,
    ): string {
        $flags =
            ($execution->alwaysMake ? 'B' : '')
            . ($execution->reporting->debugAll ? 'd' : '')
            . ($options->environmentOverrides ? 'e' : '')
            . ($execution->ignoreErrors ? 'i' : '')
            . ($execution->keepGoing ? 'k' : '')
            . ($execution->files->checkSymlinkTimes ? 'L' : '')
            . ($execution->dryRun ? 'n' : '')
            . ($execution->question ? 'q' : '')
            . ($options->noBuiltinRules ? 'r' : '')
            . ($options->noBuiltinVariables ? 'R' : '')
            . ($execution->reporting->silent ? 's' : '')
            . ($options->switches->value('keepGoing') === false ? 'S' : '')
            . ($execution->touch ? 't' : '')
            . ($options->switches->value('printDirectory') === true ? 'w' : '')
            . implode('', array_map(
                static fn(string $path): string => ' -I' . str_replace(['\\', ' '], ['\\\\', '\\ '], $path),
                $options->includes,
            ))
            . (
                $execution->parallel->jobs === 1
                    ? ''
                    : ' -j' . ($execution->parallel->jobs === 0 ? '' : $execution->parallel->jobs)
            )
            . ($execution->parallel->load === null ? '' : ' -l' . $execution->parallel->load)
            . (
                !$execution->parallel->syncSpecified && $execution->parallel->sync === 'none'
                    ? ''
                    : ' -O' . $execution->parallel->sync
            )
            . implode('', array_map(
                static fn(string $levels): string => ' --debug=' . str_replace(' ', '\\ ', $levels),
                $execution->reporting->debugLevels,
            ))
            . ($execution->parallel->auth === null ? '' : ' --jobserver-auth=' . $execution->parallel->auth)
            . ($execution->reporting->trace ? ' --trace' : '')
            . ($options->switches->value('printDirectory') === false ? ' --no-print-directory' : '')
            . ($options->switches->value('silent') === false ? ' --no-silent' : '')
            . ($execution->reporting->warnUndefinedVariables ? ' --warn-undefined-variables' : '')
            . ($execution->parallel->mutex === null ? '' : ' --sync-mutex=' . $execution->parallel->mutex)
            . implode('', array_map(
                static fn(string $text): string => ' --eval='
                . str_replace(['\\', '$', ' ', "\t", "\n"], ['\\\\', '$$', '\\ ', "\\\t", "\\\n"], $text),
                $options->evaluations,
            ))
            . ($execution->parallel->shuffle === null ? '' : ' --shuffle=' . $execution->parallel->shuffle);
        if ($legacy) {
            $flags = ltrim($flags);
            return $flags === '' || str_starts_with($flags, '-') ? $flags : '-' . $flags;
        }
        $reference = $posix ? CommandVariables::INTERNAL_NAME : 'MAKEOVERRIDES';
        $flags = str_replace('$', '$$', $flags);
        if (($variables[$reference]->expression ?? '') !== '') {
            $flags .= ' -- $(' . $reference . ')';
        }
        return $execution->parallel->jobs === 1 ? $flags : ltrim($flags);
    }
}
