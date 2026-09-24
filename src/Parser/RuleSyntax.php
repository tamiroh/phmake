<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\Assignment;
use Tamiroh\Phmake\Makefile\Pattern;
use Tamiroh\Phmake\Makefile\PrerequisiteExpression;
use Tamiroh\Phmake\Makefile\Prerequisites;

use function count;
use function ltrim;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strpbrk;
use function substr;

final class RuleSyntax
{
    /** @throws ParseException */
    public static function parse(string $header, int $line, ?string $source, ?SourceFiles $files): Rule
    {
        $colon = self::delimiter($header, ':');
        if ($colon === null) {
            throw new ParseException($line, 'missing separator');
        }
        $targets = rtrim(substr($header, 0, $colon));
        $grouped = str_ends_with($targets, '&') && !str_ends_with($targets, '\\&');
        if ($grouped) {
            $targets = substr($targets, 0, -1);
        }
        $double = ($header[$colon + 1] ?? '') === ':';
        $dependencies = ltrim(substr($header, $colon + ($double ? 2 : 1)));
        if (Assignment::parse($dependencies) !== null) {
            throw new ParseException($line, 'Unsupported target-specific variable');
        }
        $pattern = null;
        $second = self::delimiter($dependencies, ':');
        if ($second !== null) {
            $patterns = self::words(substr($dependencies, 0, $second));
            if ($patterns === []) {
                throw new ParseException($line, 'missing target pattern');
            }
            if (count($patterns) !== 1) {
                throw new ParseException($line, 'multiple target patterns');
            }
            $pattern = $patterns[0];
            if (!new Pattern($pattern)->hasWildcard()) {
                throw new ParseException($line, "target pattern contains no '%'");
            }
            $dependencies = substr($dependencies, $second + 1);
        }
        $order = self::delimiter($dependencies, '|');
        return new Rule(
            self::words($targets),
            new Prerequisites(
                self::paths($order === null ? $dependencies : substr($dependencies, 0, $order), $files),
                $order === null ? [] : self::paths(substr($dependencies, $order + 1), $files),
                [new PrerequisiteExpression($dependencies, source: $source)],
            ),
            $line,
            $double,
            $grouped,
            $pattern,
            $source,
        );
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        $result = [];
        $word = '';
        for ($index = 0; $index < strlen($text); $index++) {
            if ($text[$index] === '\\' && isset($text[$index + 1]) && str_contains(" \t\r\n:|#&", $text[$index + 1])) {
                $word .= $text[++$index];
            } elseif (str_contains(" \t\r\n", $text[$index])) {
                if ($word !== '') {
                    $result[] = $word;
                    $word = '';
                }
            } else {
                $word .= $text[$index];
            }
        }
        if ($word !== '') {
            $result[] = $word;
        }
        return $result;
    }

    private static function delimiter(string $text, string $delimiter): ?int
    {
        $depth = 0;
        for ($index = 0; $index < strlen($text); $index++) {
            if ($text[$index] === '\\') {
                $index++;
            } elseif (
                ($text[$index] === '(' || $text[$index] === '{')
                && ($depth > 0 || $index > 0 && $text[$index - 1] === '$')
            ) {
                $depth++;
            } elseif (($text[$index] === ')' || $text[$index] === '}') && $depth > 0) {
                $depth--;
            } elseif ($text[$index] === $delimiter && $depth === 0) {
                return $index;
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function paths(string $text, ?SourceFiles $files): array
    {
        $result = [];
        foreach (self::words($text) as $word) {
            $paths = strpbrk($word, '*?[') === false ? [] : $files?->matching($word) ?? [];
            $result = [...$result, ...($paths === [] ? [$word] : $paths)];
        }
        return $result;
    }
}
