<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Closure;

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
    /**
     * Maximum argument counts used when splitting calls; later commas belong to the final argument.
     * PHP_INT_MAX denotes a variable number of arguments. Each function validates required arguments.
     *
     * @var array<string, positive-int>
     */
    public const array ARGUMENT_COUNTS = [
        'call' => PHP_INT_MAX,
        'foreach' => 3,
        'if' => 3,
        'and' => PHP_INT_MAX,
        'or' => PHP_INT_MAX,
        'info' => 1,
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

    /**
     * Prepend a shared prefix to each word.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(addprefix src/,main.c util.c)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <src/main.c src/util.c>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function addprefix(?string $prefix = null, ?string $text = null): string
    {
        if ($prefix === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'addprefix'");
        }
        return implode(' ', array_map(static fn(string $word): string => $prefix . $word, self::splitWords($text)));
    }

    /**
     * Append a shared suffix to each word.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(addsuffix .o,main util)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <main.o util.o>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function addsuffix(?string $suffix = null, ?string $text = null): string
    {
        if ($suffix === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'addsuffix'");
        }
        return implode(' ', array_map(static fn(string $word): string => $word . $suffix, self::splitWords($text)));
    }

    /**
     * Expand arguments from left to right, stopping at an empty value or returning the last value.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(and yes,ready)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <ready>
     * ```
     *
     * @param list<string> $arguments
     * @param Closure(string): string $expand
     * @throws MakefileErrorException
     */
    public static function and(array $arguments, Closure $expand): string
    {
        $value = '';
        foreach ($arguments as $argument) {
            $value = $expand(trim($argument));
            if ($value === '') {
                return $value;
            }
        }
        return $value;
    }

    /**
     * Remove the final extension from each path's filename.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(basename src/main.c archive.tar.gz README)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <src/main archive.tar README>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function basename(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'basename'");
        }
        $result = [];
        foreach (self::splitWords($text) as $word) {
            $dot = self::suffixPosition($word);
            $result[] = $dot === null ? $word : substr($word, 0, $dot);
        }
        return implode(' ', $result);
    }

    /**
     * Invoke a user-defined or built-in function using expanded arguments and temporary positional parameters.
     *
     * Makefile:
     * ```makefile
     * greet = hello $(1)
     * all: ; @printf '<%s>\n' '$(call greet,world)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <hello world>
     * ```
     *
     * @param list<string> $arguments
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    public static function call(array $arguments, VariableExpander $expander, array $expanding): string
    {
        $name = trim($arguments[0] ?? '');
        $arguments[0] = $name;
        $result = $expander->invokeFunction($name, array_slice($arguments, 1), $expanding, true);
        if ($result !== null) {
            return $result;
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

    /**
     * Return each path's directory, or ./ when the path contains no slash.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(dir src/main.c README)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <src/ ./>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function dir(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'dir'");
        }
        return implode(' ', array_map(static function (string $word): string {
            $slash = strrpos($word, '/');
            return $slash === false ? './' : substr($word, 0, $slash + 1);
        }, self::splitWords($text)));
    }

    /**
     * Keep only words matching at least one of the given patterns.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(filter %.c %.h,main.c api.h README)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <main.c api.h>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function filter(?string $patterns = null, ?string $text = null): string
    {
        if ($patterns === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'filter'");
        }
        return self::filterWords($patterns, $text, false);
    }

    /**
     * Remove words matching any of the given patterns.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(filter-out %.o,main.o main.c README)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <main.c README>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function filterOut(?string $patterns = null, ?string $text = null): string
    {
        if ($patterns === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'filter-out'");
        }
        return self::filterWords($patterns, $text, true);
    }

    /**
     * Return the search string if it occurs in the text, or an empty string otherwise.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(findstring make,phmake)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <make>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function findstring(?string $find = null, ?string $text = null): string
    {
        if ($find === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'findstring'");
        }
        return str_contains($text, $find) ? $find : '';
    }

    /**
     * Return the first word.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(firstword red green blue)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <red>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function firstword(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'firstword'");
        }
        return self::splitWords($text)[0] ?? '';
    }

    /**
     * Expand the body once per list word in a temporary variable scope and join the results with spaces.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(foreach file,main util,$(file).o)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <main.o util.o>
     * ```
     *
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    public static function foreach(
        VariableExpander $expander,
        array $expanding,
        ?string $name = null,
        ?string $list = null,
        ?string $body = null,
    ): string {
        if ($name === null || $list === null || $body === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'foreach'");
        }
        $name = self::splitWords($expander->expand($name, $expanding))[0] ?? '';
        $words = self::splitWords($expander->expand($list, $expanding));
        $result = [];
        foreach ($words as $word) {
            $result[] = $expander->withVariables($name === '' ? [] : [new Variable($name, $word, false)])->expand(
                $body,
                $expanding,
            );
        }
        return implode(' ', $result);
    }

    /**
     * Expand only the true branch for a nonempty condition, or the false branch for an empty condition.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(if yes,ok,$(info skipped))'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <ok>
     * ```
     *
     * @param Closure(string): string $expand
     * @throws MakefileErrorException
     */
    public static function if(
        Closure $expand,
        ?string $condition = null,
        ?string $then = null,
        string $else = '',
    ): string {
        if ($condition === null || $then === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'if'");
        }
        return $expand($expand(trim($condition)) !== '' ? $then : $else);
    }

    /**
     * Print a message to standard output and return an empty string.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(info hello)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * hello
     * <>
     * ```
     */
    public static function info(string $message, ?Output $output): string
    {
        $output?->write($message . "\n");
        return '';
    }

    /**
     * Concatenate corresponding words from two lists, preserving unmatched trailing words.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(join a b c,.o .h)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <a.o b.h c>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function join(?string $left = null, ?string $right = null): string
    {
        if ($left === null || $right === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'join'");
        }
        $left = self::splitWords($left);
        $right = self::splitWords($right);
        $result = [];
        for ($index = 0; $index < max(count($left), count($right)); $index++) {
            $result[] = ($left[$index] ?? '') . ($right[$index] ?? '');
        }
        return implode(' ', $result);
    }

    /**
     * Return the last word.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(lastword red green blue)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <blue>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function lastword(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'lastword'");
        }
        return array_slice(self::splitWords($text), -1)[0] ?? '';
    }

    /**
     * Remove the directory portion from each path.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(notdir src/main.c lib/util.c)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <main.c util.c>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function notdir(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'notdir'");
        }
        return implode(' ', array_map(static function (string $word): string {
            $slash = strrpos($word, '/');
            return $slash === false ? $word : substr($word, $slash + 1);
        }, self::splitWords($text)));
    }

    /**
     * Return the first nonempty expanded argument without evaluating the remaining arguments.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(or ,fallback,$(info skipped))'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <fallback>
     * ```
     *
     * @param list<string> $arguments
     * @param Closure(string): string $expand
     * @throws MakefileErrorException
     */
    public static function or(array $arguments, Closure $expand): string
    {
        $value = '';
        foreach ($arguments as $argument) {
            $value = $expand(trim($argument));
            if ($value !== '') {
                return $value;
            }
        }
        return $value;
    }

    /**
     * Replace words matching a percent pattern, preserving unmatched words.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(patsubst %.c,%.o,main.c util.c README)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <main.o util.o README>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function patsubst(?string $from = null, ?string $to = null, ?string $text = null): string
    {
        if ($from === null || $to === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'patsubst'");
        }
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
        foreach (self::splitWords($text) as $word) {
            $stem = $pattern->match($word);
            $value = $stem === null ? $word : $replacement->substitute($stem);
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return implode(' ', $result);
    }

    /**
     * Sort words lexicographically and remove duplicates.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(sort b a b c)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <a b c>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function sort(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'sort'");
        }
        $words = array_values(array_unique(self::splitWords($text)));
        sort($words, SORT_STRING);
        return implode(' ', $words);
    }

    /**
     * Remove surrounding whitespace and collapse whitespace between words to a single space.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(strip   a   b   )'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <a b>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function strip(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'strip'");
        }
        return implode(' ', self::splitWords($text));
    }

    /**
     * Replace every occurrence of a substring.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(subst cat,dog,cat-cat)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <dog-dog>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function subst(?string $from = null, ?string $to = null, ?string $text = null): string
    {
        if ($from === null || $to === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'subst'");
        }
        return $from === '' ? $text . $to : str_replace($from, $to, $text);
    }

    /**
     * Return each path's final extension including its dot, omitting paths without extensions.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(suffix src/main.c archive.tar.gz README)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <.c .gz>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function suffix(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'suffix'");
        }
        $result = [];
        foreach (self::splitWords($text) as $word) {
            $dot = self::suffixPosition($word);
            if ($dot !== null) {
                $result[] = substr($word, $dot);
            }
        }
        return implode(' ', $result);
    }

    /**
     * Return the word at the given one-based position.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(word 2,red green blue)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <green>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function word(?string $position = null, ?string $text = null): string
    {
        if ($position === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'word'");
        }
        return self::splitWords($text)[self::index($position, 'word', 'first', 1) - 1] ?? '';
    }

    /**
     * Return words between the given one-based positions, including both endpoints.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(wordlist 2,3,red green blue white)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <green blue>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function wordlist(?string $start = null, ?string $end = null, ?string $text = null): string
    {
        if ($start === null || $end === null || $text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'wordlist'");
        }
        $first = self::index($start, 'wordlist', 'first', 1);
        $last = self::index($end, 'wordlist', 'second', 0);
        return $last < $first ? '' : implode(' ', array_slice(self::splitWords($text), $first - 1, $last - $first + 1));
    }

    /**
     * Return the number of whitespace-separated words.
     *
     * Makefile:
     * ```makefile
     * all: ; @printf '<%s>\n' '$(words red green blue)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <3>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function words(?string $text = null): string
    {
        if ($text === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'words'");
        }
        return (string) count(self::splitWords($text));
    }

    private static function filterWords(string $patterns, string $text, bool $exclude): string
    {
        $patterns = array_map(static fn(string $word): Pattern => new Pattern($word), self::splitWords($patterns));
        return implode(' ', array_filter(self::splitWords($text), static function (string $word) use (
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

    /** @return list<string> */
    private static function splitWords(string $text): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? [] : $words;
    }

    private static function suffixPosition(string $word): ?int
    {
        $slash = strrpos($word, '/');
        $dot = strrpos($word, '.');
        return $dot !== false && ($slash === false || $dot > $slash) ? $dot : null;
    }
}
