<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use LogicException;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function ltrim;
use function max;
use function preg_match;
use function preg_replace_callback;
use function preg_split;
use function sort;
use function str_contains;
use function str_replace;
use function strcmp;
use function strlen;
use function strrpos;
use function substr;
use function trim;

final class Functions
{
    /** @var array<string, positive-int> */
    private const array ARGUMENT_COUNTS = [
        'subst' => 3,
        'patsubst' => 3,
        'strip' => 1,
        'findstring' => 2,
        'filter' => 2,
        'filter-out' => 2,
        'sort' => 1,
        'word' => 2,
        'wordlist' => 3,
        'words' => 1,
        'firstword' => 1,
        'lastword' => 1,
        'addprefix' => 2,
        'addsuffix' => 2,
        'join' => 2,
        'dir' => 1,
        'notdir' => 1,
        'basename' => 1,
        'suffix' => 1,
    ];

    /** @return ?positive-int */
    public static function argumentCount(string $name): ?int
    {
        return self::ARGUMENT_COUNTS[$name] ?? null;
    }

    /**
     * @param list<string> $arguments
     * @throws MakefileErrorException
     */
    public static function expand(string $name, array $arguments): string
    {
        if (count($arguments) !== self::argumentCount($name)) {
            throw new MakefileErrorException("insufficient number of arguments to function '$name'");
        }
        $first = $arguments[0] ?? '';
        $second = $arguments[1] ?? '';
        $third = $arguments[2] ?? '';
        return match ($name) {
            'subst' => $first === '' ? $third . $second : str_replace($first, $second, $third),
            'patsubst' => self::replacePattern($first, $second, $third),
            'strip' => implode(' ', self::words($first)),
            'findstring' => str_contains($second, $first) ? $first : '',
            'filter', 'filter-out' => self::filter($first, $second, $name === 'filter-out'),
            'sort' => self::sortWords($first),
            'words' => (string) count(self::words($first)),
            'word' => self::words($second)[self::index($first, $name, 'first', 1) - 1] ?? '',
            'wordlist' => self::wordlist($first, $second, $third),
            'firstword' => self::words($first)[0] ?? '',
            'lastword' => array_slice(self::words($first), -1)[0] ?? '',
            'addprefix' => implode(' ', array_map(
                static fn(string $word): string => $first . $word,
                self::words($second),
            )),
            'addsuffix' => implode(' ', array_map(
                static fn(string $word): string => $word . $first,
                self::words($second),
            )),
            'join' => self::join($first, $second),
            'dir', 'notdir', 'basename', 'suffix' => self::filenames($name, $first),
            default => throw new LogicException("Unknown function: $name"),
        };
    }

    private static function filenames(string $function, string $text): string
    {
        $result = [];
        foreach (self::words($text) as $word) {
            $slash = strrpos($word, '/');
            $dot = strrpos($word, '.');
            $hasSuffix = $dot !== false && ($slash === false || $dot > $slash);
            $part = match ($function) {
                'dir' => $slash === false ? './' : substr($word, 0, $slash + 1),
                'notdir' => $slash === false ? $word : substr($word, $slash + 1),
                'basename' => $hasSuffix ? substr($word, 0, $dot) : $word,
                'suffix' => $hasSuffix ? substr($word, $dot) : '',
                default => throw new LogicException("Unknown filename function: $function"),
            };
            if ($function !== 'suffix' || $part !== '') {
                $result[] = $part;
            }
        }
        return implode(' ', $result);
    }

    private static function filter(string $patterns, string $text, bool $exclude): string
    {
        $patterns = array_map(static fn(string $word): Pattern => new Pattern($word), self::words($patterns));
        return implode(' ', array_filter(self::words($text), static function (string $word) use (
            $patterns,
            $exclude,
        ): bool {
            foreach ($patterns as $pattern) {
                if ($pattern->match($word) !== null) {
                    return !$exclude;
                }
            }
            return $exclude;
        }));
    }

    /** @throws MakefileErrorException */
    private static function index(string $value, string $function, string $position, int $minimum): int
    {
        if (preg_match('/^[0-9]+$/D', trim($value)) !== 1) {
            throw new MakefileErrorException("invalid $position argument to '$function' function: '$value'");
        }
        $digits = ltrim(trim($value), '0');
        if (
            strlen($digits) > strlen((string) PHP_INT_MAX)
            || strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0
        ) {
            throw new MakefileErrorException(
                "invalid $position argument to '$function' function: '$value' out of range",
            );
        }
        if ((int) $digits < $minimum) {
            throw new MakefileErrorException("$position argument to '$function' function must be greater than 0");
        }
        return (int) $digits;
    }

    private static function join(string $left, string $right): string
    {
        $left = self::words($left);
        $right = self::words($right);
        $result = [];
        for ($index = 0; $index < max(count($left), count($right)); $index++) {
            $result[] = ($left[$index] ?? '') . ($right[$index] ?? '');
        }
        return implode(' ', $result);
    }

    private static function replacePattern(string $from, string $to, string $text): string
    {
        $pattern = new Pattern($from);
        $replacement = new Pattern($to);
        if (!$pattern->hasWildcard()) {
            return preg_replace_callback(
                '/\S+/',
                static function (array $match) use ($pattern, $replacement): string {
                    /** @var array{non-empty-string} $match */
                    return $pattern->match($match[0]) === null ? $match[0] : $replacement->substitute('%');
                },
                $text,
            ) ?? $text;
        }
        $result = [];
        foreach (self::words($text) as $word) {
            $stem = $pattern->match($word);
            $value = $stem === null ? $word : $replacement->substitute($stem);
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return implode(' ', $result);
    }

    private static function sortWords(string $text): string
    {
        $words = array_values(array_unique(self::words($text)));
        sort($words, SORT_STRING);
        return implode(' ', $words);
    }

    /** @throws MakefileErrorException */
    private static function wordlist(string $start, string $end, string $text): string
    {
        $first = self::index($start, 'wordlist', 'first', 1);
        $last = self::index($end, 'wordlist', 'second', 0);
        return $last < $first ? '' : implode(' ', array_slice(self::words($text), $first - 1, $last - $first + 1));
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? [] : $words;
    }
}
