<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Syntax;

use function ltrim;
use function preg_match;
use function str_contains;
use function strcspn;
use function strlen;
use function strspn;
use function substr;
use function trim;

final readonly class AssignmentSyntax
{
    /**
     * @return array{string, string, string}|null
     */
    public static function parse(string $text, bool $allowWhitespace = false): ?array
    {
        if (!str_contains($text, '=')) {
            return null;
        }
        $text = ltrim($text);
        $depth = 0;
        $length = strlen($text);
        // Skip in C to the next character that can matter; names and values rarely contain one.
        $stops = "(){} \t:!+?=#";
        for ($index = strcspn($text, $stops); $index < $length; $index += 1 + strcspn($text, $stops, $index + 1)) {
            if (
                ($text[$index] === '(' || $text[$index] === '{')
                && ($depth > 0 || $index > 0 && $text[$index - 1] === '$')
            ) {
                $depth++;
            } elseif (($text[$index] === ')' || $text[$index] === '}') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0) {
                if (!$allowWhitespace && str_contains(" \t", $text[$index])) {
                    $index += strspn($text, " \t", $index);
                    if (preg_match('/^(:::=|::=|:=|!=|\+=|\?=|=)/', substr($text, $index)) !== 1) {
                        return null;
                    }
                }
                $matches = [];
                if (
                    str_contains(':!+?=', $text[$index])
                    && preg_match('/^(:::=|::=|:=|!=|\+=|\?=|=)/', substr($text, $index), $matches) === 1
                ) {
                    /** @var array{non-falsy-string, ':::='|'::='|':='|'!='|'+='|'?='|'='} $matches */
                    return [
                        trim(substr($text, 0, $index)),
                        $matches[1],
                        ltrim(substr($text, $index + strlen($matches[1]))),
                    ];
                }
                if ($text[$index] === ':' || $text[$index] === '#') {
                    return null;
                }
            }
        }
        return null;
    }
}
