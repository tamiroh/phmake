<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Closure;
use LogicException;

use function count;
use function trim;

final class ConditionalFunctions
{
    /**
     * @param list<string> $arguments
     * @param Closure(string): string $expand
     * @throws MakefileErrorException
     */
    public static function expand(string $name, array $arguments, Closure $expand): string
    {
        if ($name === 'if') {
            if (count($arguments) < 2) {
                throw new MakefileErrorException("insufficient number of arguments to function 'if'");
            }
            return $expand($arguments[$expand(trim($arguments[0])) !== '' ? 1 : 2] ?? '');
        }
        if ($name !== 'and' && $name !== 'or') {
            throw new LogicException("Unknown conditional function: $name");
        }
        $value = '';
        foreach ($arguments as $argument) {
            $value = $expand(trim($argument));
            if ($name === 'and' && $value === '' || $name === 'or' && $value !== '') {
                return $value;
            }
        }
        return $value;
    }
}
