<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function count;
use function implode;
use function preg_split;

final class ForeachFunction
{
    /**
     * @param list<string> $arguments
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    public static function expand(array $arguments, VariableExpander $expander, array $expanding): string
    {
        if (count($arguments) < 3) {
            throw new MakefileErrorException("insufficient number of arguments to function 'foreach'");
        }
        $name = Functions::expand('firstword', [$expander->expand($arguments[0], $expanding)]);
        $words = preg_split('/\s+/', $expander->expand($arguments[1], $expanding), -1, PREG_SPLIT_NO_EMPTY);
        $result = [];
        foreach ($words === false ? [] : $words as $word) {
            $result[] = $expander->withVariables($name === '' ? [] : [new Variable($name, $word, false)])->expand(
                $arguments[2],
                $expanding,
            );
        }
        return implode(' ', $result);
    }
}
