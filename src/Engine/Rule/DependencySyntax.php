<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Rule;

use Tamiroh\Phmake\Engine\IO\Filesystem;

use function preg_split;
use function str_contains;
use function strcspn;
use function strlen;
use function strpbrk;
use function substr;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Tokenization shared by first and secondary prerequisite expansion.
 *
 * @internal
 */
final class DependencySyntax
{
    /**
     * @pure
     */
    public static function delimiter(string $text, string $delimiter): ?int
    {
        if (!str_contains($text, $delimiter)) {
            return null;
        }
        $depth = 0;
        $length = strlen($text);
        // Skip in C to the next character that can matter; long paths rarely contain one.
        $stops = '\\(){}' . $delimiter;
        for ($index = strcspn($text, $stops); $index < $length; $index += 1 + strcspn($text, $stops, $index + 1)) {
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

    /**
     * @pure
     *
     * @return list<string>
     */
    public static function expressions(string $text): array
    {
        $result = [];
        $start = 0;
        $depth = 0;
        for ($index = 0; $index < strlen($text); $index++) {
            if ($text[$index] === '\\') {
                $index++;
            } elseif ($text[$index] === '$' && isset($text[$index + 1]) && !str_contains('({', $text[$index + 1])) {
                $index++;
            } elseif (
                ($text[$index] === '(' || $text[$index] === '{')
                && ($depth > 0 || $index > 0 && $text[$index - 1] === '$')
            ) {
                $depth++;
            } elseif (($text[$index] === ')' || $text[$index] === '}') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && str_contains(" \t\r\n|", $text[$index])) {
                if ($start < $index) {
                    $result[] = substr($text, $start, $index - $start);
                }
                if ($text[$index] === '|') {
                    $result[] = '|';
                }
                $start = $index + 1;
            }
        }
        if ($start < strlen($text)) {
            $result[] = substr($text, $start);
        }
        return $result;
    }

    public static function parse(string $text, ?Filesystem $filesystem = null, string $directory = ''): Prerequisites
    {
        $order = self::delimiter($text, '|');
        return new Prerequisites(
            self::paths($order === null ? $text : substr($text, 0, $order), $filesystem, $directory),
            $order === null ? [] : self::paths(substr($text, $order + 1), $filesystem, $directory),
        );
    }

    /**
     * @pure
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        // Without backslashes only whitespace separates words, so split in C.
        if (!str_contains($text, '\\')) {
            $words = preg_split('/[ \t\r\n]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            return $words === false ? [] : $words;
        }
        $result = [];
        $word = '';
        for ($index = 0; $index < strlen($text); $index++) {
            if ($text[$index] === '\\') {
                $start = $index;
                while (($text[$index] ?? '') === '\\') {
                    $index++;
                }
                $count = $index - $start;
                $next = $text[$index] ?? '';
                if ($next === '' || !str_contains(" \t\r\n:|#&", $next)) {
                    $word .= substr($text, $start, $count);
                    $index--;
                    continue;
                }
                $word .= substr($text, $start, $count >> 1);
                if (($count % 2) === 1) {
                    $word .= $next;
                    continue;
                }
            }
            if (str_contains(" \t\r\n", $text[$index])) {
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

    /**
     * @return list<string>
     */
    private static function paths(string $text, ?Filesystem $filesystem, string $directory): array
    {
        $result = [];
        foreach (self::words(ArchiveMember::expand($text)) as $word) {
            $word = FileName::normalize($directory . $word);
            $paths = strpbrk($word, '*?[') === false ? [] : $filesystem?->matching($word) ?? [];
            $result = [...$result, ...($paths === [] ? [$word] : $paths)];
        }
        return $result;
    }
}
