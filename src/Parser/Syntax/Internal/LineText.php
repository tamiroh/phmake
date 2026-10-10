<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Syntax\Internal;

use LogicException;

use function intdiv;
use function str_contains;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Source-only escaping and recipe delimiters; never expands expressions.
 */
final readonly class LineText
{
    public static function removeComment(string $line): string
    {
        if (!str_contains($line, '#')) {
            return $line;
        }
        $result = '';
        $depth = 0;
        for ($index = 0; $index < strlen($line); $index++) {
            if ($line[$index] === '\\') {
                $start = $index;
                while (($line[$index] ?? '') === '\\') {
                    $index++;
                }
                $count = $index - $start;
                if ($count < 0) {
                    throw new LogicException('Backslash count must be non-negative');
                }
                if (($line[$index] ?? '') === '#' && $depth === 0) {
                    /**
                     * Dividing a non-negative count by 2 yields a non-negative result.
                     * Dividing by 2 cannot cause division by zero or integer overflow.
                     *
                     * @mago-expect analysis:unhandled-thrown-type,unhandled-thrown-type,possibly-invalid-argument
                     */
                    $result .= str_repeat('\\', intdiv($count, num2: 2));
                    if (($count % 2) === 0) {
                        break;
                    }
                    $result .= '#';
                    continue;
                }
                $result .= str_repeat('\\', $count);
                $index--;
                continue;
            }
            if (
                ($line[$index] === '(' || $line[$index] === '{')
                && ($depth > 0 || $index > 0 && $line[$index - 1] === '$')
            ) {
                $depth++;
            } elseif (($line[$index] === ')' || $line[$index] === '}') && $depth > 0) {
                $depth--;
            }
            if ($line[$index] === '#' && $depth === 0) {
                break;
            }
            $result .= $line[$index];
        }
        return $result;
    }

    /**
     * @return array{string, ?string}
     */
    public static function splitRecipe(string $line): array
    {
        if (!str_contains($line, ';')) {
            return [self::removeComment($line), null];
        }
        $depth = 0;
        for ($index = 0; $index < strlen($line); $index++) {
            if ($line[$index] === '\\') {
                $index++;
                continue;
            }
            if ($line[$index] === '$' && isset($line[$index + 1])) {
                if ($line[$index + 1] === '(' || $line[$index + 1] === '{') {
                    $depth++;
                }
                $index++;
                continue;
            }
            if (($line[$index] === '(' || $line[$index] === '{') && $depth > 0) {
                $depth++;
            } elseif (($line[$index] === ')' || $line[$index] === '}') && $depth > 0) {
                $depth--;
            }
            if ($depth > 0) {
                continue;
            }
            if ($line[$index] === '#') {
                return [self::removeComment($line), null];
            }
            if ($line[$index] === ';') {
                return [self::removeComment(substr($line, offset: 0, length: $index)), substr($line, $index + 1)];
            }
        }
        return [self::removeComment($line), null];
    }
}
