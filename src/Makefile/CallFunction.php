<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function max;
use function trim;

final class CallFunction
{
    /**
     * @param list<string> $arguments
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    public static function expand(
        array $arguments,
        VariableExpander $expander,
        array $expanding,
        ?Output $output = null,
    ): string {
        $name = trim($arguments[0] ?? '');
        $arguments[0] = $name;
        $argumentCount = Functions::argumentCount($name);
        if ($argumentCount !== null) {
            return Functions::expand($name, array_slice($arguments, 1, $argumentCount));
        }
        if (in_array($name, ['if', 'and', 'or'], strict: true)) {
            return ConditionalFunctions::expand(
                $name,
                array_slice($arguments, 1, $name === 'if' ? 3 : null),
                static fn(string $argument): string => $argument,
            );
        }
        if ($name === 'info') {
            $output?->write(($arguments[1] ?? '') . "\n");
            return '';
        }
        if ($name === 'call') {
            return self::expand(array_slice($arguments, 1), $expander, $expanding, $output);
        }
        if ($name === 'foreach') {
            return ForeachFunction::expand(array_slice($arguments, 1, 3), $expander, $expanding);
        }
        $variable = $expander->variable($name);
        if ($variable === null) {
            return '';
        }
        if (!$variable->recursive) {
            return $variable->expression;
        }
        $variables = [];
        $parameters = max($expander->callParameters, count($arguments) - 1);
        for ($index = 0; $index <= $parameters; $index++) {
            $variables[] = new Variable((string) $index, $arguments[$index] ?? '', false);
        }
        return $expander->withVariables($variables, $parameters)->expand(
            $variable->expression,
            array_values(array_filter($expanding, static fn(string $variable): bool => $variable !== $name)),
        );
    }
}
