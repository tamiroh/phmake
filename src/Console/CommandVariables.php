<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Evaluation\Variable;

use function array_reverse;
use function implode;
use function str_replace;

/**
 * Keep command-line definitions unexpanded for MAKEOVERRIDES and recursive make.
 */
final class CommandVariables
{
    public const string INTERNAL_NAME = '-*-command-variables-*-';

    /**
     * @param array<string, Variable> $variables
     * @param array<string, Variable> $defaults
     *
     * @return array<string, Variable>
     */
    public static function definitions(array $variables, array $defaults): array
    {
        $assignments = [];
        foreach (array_reverse($variables) as $variable) {
            if ($variable->origin !== 'command line') {
                continue;
            }
            $assignments[] = str_replace(
                ['\\', '$', ' ', "\t", "\n"],
                ['\\\\', '$$', '\\ ', "\\\t", "\\\n"],
                $variable->name . ($variable->recursive ? '=' : ':=') . $variable->expression,
            );
        }
        if ($assignments === []) {
            return $defaults;
        }
        $defaults[self::INTERNAL_NAME] = new Variable(
            self::INTERNAL_NAME,
            implode(' ', $assignments),
            false,
            'automatic',
            export: false,
        );
        $defaults['MAKEOVERRIDES'] ??= new Variable('MAKEOVERRIDES', '${' . self::INTERNAL_NAME . '}', true, 'default');
        return $defaults;
    }
}
