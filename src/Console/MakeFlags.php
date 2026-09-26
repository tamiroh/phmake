<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Evaluation\Assignment;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

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
        CommandLine $commandLine,
        array &$variables,
        bool $posix = false,
        ?int $makefileRestart = null,
    ): void {
        new Assignment('MFLAGS', '=', self::expression($commandLine, $makefileRestart, legacy: true))->apply(
            $variables,
            $commandLine->environmentOverrides ? 'environment override' : 'environment',
        );
        new Assignment(
            'MAKEFLAGS',
            '=',
            self::expression($commandLine, $makefileRestart, variables: $variables, posix: $posix),
        )->apply($variables, $commandLine->environmentOverrides ? 'environment override' : 'file');
    }

    /**
     * @param array<string, Variable> $variables
     */
    private static function expression(
        CommandLine $commandLine,
        ?int $makefileRestart = null,
        bool $legacy = false,
        array $variables = [],
        bool $posix = false,
    ): string {
        $execution = $makefileRestart === null
            ? $commandLine->execution
            : $commandLine->execution->forMakefiles($makefileRestart);
        $flags =
            ($execution->alwaysMake ? 'B' : '')
            . ($execution->reporting->debugAll ? 'd' : '')
            . ($commandLine->environmentOverrides ? 'e' : '')
            . ($execution->ignoreErrors ? 'i' : '')
            . ($execution->keepGoing ? 'k' : '')
            . ($execution->files->checkSymlinkTimes ? 'L' : '')
            . ($execution->dryRun ? 'n' : '')
            . ($execution->question ? 'q' : '')
            . ($commandLine->noBuiltinRules ? 'r' : '')
            . ($commandLine->noBuiltinVariables ? 'R' : '')
            . ($execution->reporting->silent ? 's' : '')
            . ($commandLine->switches->value('keepGoing') === false ? 'S' : '')
            . ($execution->touch ? 't' : '')
            . ($commandLine->switches->value('printDirectory') === true ? 'w' : '')
            . implode('', array_map(
                static fn(string $path): string => ' -I' . str_replace(['\\', ' '], ['\\\\', '\\ '], $path),
                $commandLine->input->includes,
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
            . ($commandLine->switches->value('printDirectory') === false ? ' --no-print-directory' : '')
            . ($commandLine->switches->value('silent') === false ? ' --no-silent' : '')
            . ($execution->reporting->warnUndefinedVariables ? ' --warn-undefined-variables' : '')
            . ($execution->parallel->mutex === null ? '' : ' --sync-mutex=' . $execution->parallel->mutex)
            . implode('', array_map(
                static fn(string $text): string => ' --eval='
                . str_replace(['\\', '$', ' ', "\t", "\n"], ['\\\\', '$$', '\\ ', "\\\t", "\\\n"], $text),
                $commandLine->input->evaluations,
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
