<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function count;
use function preg_quote;
use function preg_replace;
use function strlen;
use function substr;

final class ExpansionSyntax
{
    /**
     * @return list<string>
     */
    public static function arguments(string $text, int $limit, string $opening): array
    {
        $arguments = [];
        $start = 0;
        $depth = 0;
        $closing = $opening === '(' ? ')' : '}';
        for ($index = 0; $index < strlen($text) && count($arguments) < ($limit - 1); $index++) {
            if ($text[$index] === $opening) {
                $depth++;
            } elseif ($text[$index] === $closing) {
                $depth--;
            } elseif ($text[$index] === ',' && $depth === 0) {
                $arguments[] = substr($text, $start, $index - $start);
                $start = $index + 1;
            }
        }
        $arguments[] = substr($text, $start);
        return $arguments;
    }

    /**
     * @throws MakefileErrorException
     */
    public static function readReference(string $expression, int &$index): string
    {
        $opening = $expression[$index];
        $closing = $opening === '(' ? ')' : '}';
        $start = ++$index;
        $depth = 1;
        while ($index < strlen($expression)) {
            if ($expression[$index] === $opening) {
                $depth++;
            } elseif ($expression[$index] === $closing && --$depth === 0) {
                $reference = substr($expression, $start, $index - $start);
                return preg_replace('/[ \t]*' . preg_quote("\\\n", '/') . '[ \t]*/', ' ', $reference) ?? $reference;
            }
            $index++;
        }
        throw new MakefileErrorException('Unterminated variable reference');
    }
}
