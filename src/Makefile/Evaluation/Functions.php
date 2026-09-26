<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use Closure;
use Tamiroh\Phmake\Makefile\Evaluation\Environment\ExportingShell;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\Pattern;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function ltrim;
use function max;
use function preg_match;
use function preg_replace_callback;
use function preg_split;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strcspn;
use function strlen;
use function strrpos;
use function substr;
use function trim;

use const PHP_INT_MAX;
use const PREG_SPLIT_NO_EMPTY;
use const SORT_STRING;

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
        'let' => 3,
        'eval' => 1,
        'guile' => 1,
        'shell' => 1,
        'file' => 2,
        'wildcard' => 1,
        'abspath' => 1,
        'realpath' => 1,
        'intcmp' => 5,
        'if' => 3,
        'and' => PHP_INT_MAX,
        'or' => PHP_INT_MAX,
        'info' => 1,
        'warning' => 1,
        'error' => 1,
        'value' => 1,
        'flavor' => 1,
        'origin' => 1,
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
     * Normalize paths to absolute names without requiring existing files.
     *
     * Makefile:
     * ```makefile
     * all: ; @echo $(abspath /tmp/../example)
     * ```
     * Run:
     * ```text
     * $ ./phmake
     * /example
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function abspath(Filesystem $files, ?string $paths = null): string
    {
        if ($paths === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'abspath'");
        }
        $result = [];
        foreach (self::splitWords($paths) as $path) {
            $normalized = '';
            foreach (explode(
                '/',
                str_starts_with($path, '/') ? $path : $files->workingDirectory() . '/' . $path,
            ) as $part) {
                if ($part === '..') {
                    $slash = strrpos($normalized, '/');
                    $normalized = $slash === false ? '' : substr($normalized, 0, $slash);
                } elseif ($part !== '' && $part !== '.') {
                    $normalized .= '/' . $part;
                }
            }
            $result[] = $normalized === '' ? '/' : $normalized;
        }
        return implode(' ', $result);
    }

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
     *
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
     *
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
            if ($name !== '') {
                UndefinedVariable::warn($expander, $name);
            }
            return '';
        }
        if (!$variable->recursive) {
            return $variable->expression;
        }
        $variables = [];
        $parameters = max($expander->callParameters, count($arguments) - 1);
        for ($index = 0; $index <= $parameters; $index++) {
            $variables[] = new Variable((string) $index, $arguments[$index] ?? '', false, 'automatic');
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
     * Stop expansion with a fatal diagnostic at the current source location.
     *
     * Makefile:
     * ```makefile
     * all: ; @echo $(error stopped)
     * ```
     *
     * Run (exit status 2):
     * ```text
     * $ ./phmake
     * Makefile:1: *** stopped.  Stop.
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function error(string $message, ?string $source = null): never
    {
        throw new MakefileErrorException($message, $source);
    }

    /**
     * Parse expanded text as Makefile syntax and return an empty string.
     *
     * Makefile:
     * ```makefile
     * $(eval NAME := world)
     * all: ; @echo hello $(NAME)
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * hello world
     * ```
     *
     * @param Closure(string, VariableExpander): void|null $evaluate
     *
     * @throws MakefileErrorException
     */
    public static function eval(string $text, ?Closure $evaluate, VariableExpander $expander): string
    {
        if ($evaluate === null) {
            throw new MakefileErrorException('eval requires a Makefile evaluation context', $expander->source);
        }
        $evaluate($text, $expander);
        return '';
    }

    /**
     * Read a file or write text, adding a final newline when needed.
     *
     * Makefile:
     * ```makefile
     * $(file >message.txt,hello)
     * all: ; @echo $(file <message.txt)
     * ```
     * Run:
     * ```text
     * $ ./phmake
     * hello
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function file(
        Filesystem $files,
        ?string $operation = null,
        ?string $text = null,
        ?string $source = null,
    ): string {
        $operation = trim($operation ?? '');
        if (!str_starts_with($operation, '>') && !str_starts_with($operation, '<')) {
            throw new MakefileErrorException('file: invalid file operation: ' . $operation, $source);
        }
        $append = str_starts_with($operation, '>>');
        $path = trim(substr($operation, $append ? 2 : 1));
        if ($path === '') {
            throw new MakefileErrorException('file: missing filename', $source);
        }
        try {
            if ($operation[0] === '<') {
                if ($text !== null) {
                    throw new MakefileErrorException('file: too many arguments', $source);
                }
                $contents = $files->read($path) ?? '';
                return str_ends_with($contents, "\n") ? substr($contents, 0, -1) : $contents;
            }
            $files->write($path, $text === null ? '' : $text . (str_ends_with($text, "\n") ? '' : "\n"), $append);
            return '';
        } catch (MakefileErrorException $error) {
            throw new MakefileErrorException($error->getMessage(), $source);
        }
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
     * Return whether a variable is simple, recursive, or undefined.
     *
     * Makefile:
     * ```makefile
     * greeting := hello
     * all: ; @printf '<%s>\n' '$(flavor greeting)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <simple>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function flavor(VariableExpander $expander, ?string $name = null): string
    {
        if ($name === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'flavor'");
        }
        $variable = $expander->variable($name);
        return $variable === null ? 'undefined' : ($variable->recursive ? 'recursive' : 'simple');
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
     *
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
            throw new MakefileErrorException(
                'insufficient number of arguments ('
                . ($name === null ? 0 : ($list === null ? 1 : 2))
                . ") to function 'foreach'",
                $expander->definitionSource ?? $expander->source,
            );
        }
        $name = self::splitWords($expander->expand($name, $expanding))[0] ?? '';
        $words = self::splitWords($expander->expand($list, $expanding));
        $result = [];
        foreach ($words as $word) {
            $result[] = $expander->withVariables(
                $name === '' ? [] : [new Variable($name, $word, false, 'automatic')],
            )->expand($body, $expanding);
        }
        return implode(' ', $result);
    }

    /**
     * Evaluate Scheme with GNU Guile and convert its result to make words.
     *
     * Makefile:
     * ```makefile
     * all:
     *
     * 	@echo $(guile (+ 2 3))
     * ```
     * Running `phmake` prints `5`.
     *
     * @throws MakefileErrorException
     */
    public static function guile(string $expression, VariableExpander $expander): string
    {
        return $expander->context->modules->guile($expression, $expander);
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
     *
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
     * Compare arbitrary-size decimal integers and expand only the selected branch.
     *
     * Makefile:
     * ```makefile
     * all: ; @echo $(intcmp 2,3,less,equal,greater)
     * ```
     * Run:
     * ```text
     * $ ./phmake
     * less
     * ```
     *
     * @param Closure(string): string $expand
     * @param list<string> $arguments
     *
     * @throws MakefileErrorException
     */
    public static function intcmp(Closure $expand, array $arguments, ?string $source = null): string
    {
        if (count($arguments) < 2) {
            throw new MakefileErrorException("insufficient number of arguments to function 'intcmp'", $source);
        }
        $left = self::integer($expand($arguments[0]), 'first', $source);
        $right = self::integer($expand($arguments[1]), 'second', $source);
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        $comparison = $leftNegative !== $rightNegative
            ? ($leftNegative ? -1 : 1)
            : (strlen($left) === strlen($right) ? strcmp($left, $right) : strlen($left) <=> strlen($right))
            * ($leftNegative ? -1 : 1);
        if (!isset($arguments[2])) {
            return $comparison === 0 ? $left : '';
        }
        return $expand(
            $comparison < 0
                ? $arguments[2]
                : ($comparison === 0 ? $arguments[3] ?? '' : $arguments[4] ?? $arguments[3] ?? ''),
        );
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
     * Bind list words to local names, assigning the remaining text to the last name.
     *
     * Makefile:
     * ```makefile
     * all: ; @echo $(let first rest,one two three,$(rest) $(first))
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * two three one
     * ```
     *
     * @param list<string> $expanding
     *
     * @throws MakefileErrorException
     */
    public static function let(
        VariableExpander $expander,
        array $expanding,
        ?string $names = null,
        ?string $list = null,
        ?string $body = null,
    ): string {
        if ($names === null || $list === null || $body === null) {
            throw new MakefileErrorException(
                'insufficient number of arguments ('
                . ($names === null ? 0 : ($list === null ? 1 : 2))
                . ") to function 'let'",
                $expander->definitionSource ?? $expander->source,
            );
        }
        $names = self::splitWords($expander->expand($names, $expanding));
        $list = $expander->expand($list, $expanding);
        $variables = [];
        foreach ($names as $index => $name) {
            $list = ltrim($list);
            if ($index === (count($names) - 1)) {
                $value = $list;
            } else {
                $length = strcspn($list, " \t\r\n\v\f");
                $value = substr($list, 0, $length);
                $list = substr($list, $length);
            }
            $variables[] = new Variable($name, $value, false, 'automatic');
        }
        return $expander->withVariables($variables)->expand($body, $expanding);
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
     *
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
     * Return where a variable was defined, or undefined when it does not exist.
     *
     * Makefile:
     * ```makefile
     * greeting = hello
     * all: ; @printf '<%s>\n' '$(origin greeting)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <file>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function origin(VariableExpander $expander, ?string $name = null): string
    {
        if ($name === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'origin'");
        }
        return $expander->variable($name)->origin ?? 'undefined';
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
     * Resolve symlinks and return absolute paths for existing files only.
     *
     * Makefile:
     * ```makefile
     * all: ; @echo $(notdir $(realpath Makefile missing-file))
     * ```
     * Run:
     * ```text
     * $ ./phmake
     * Makefile
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function realpath(Filesystem $files, ?string $paths = null): string
    {
        if ($paths === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'realpath'");
        }
        $result = [];
        foreach (self::splitWords($paths) as $path) {
            $resolved = $files->realpath($path);
            if ($resolved !== null) {
                $result[] = $resolved;
            }
        }
        return implode(' ', $result);
    }

    /**
     * Run a shell command and replace output line endings with spaces.
     *
     * Makefile:
     * ```makefile
     * all: ; @echo $(shell printf 'one\ntwo\n')
     * ```
     * Run:
     * ```text
     * $ ./phmake
     * one two
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function shell(
        Shell $shell,
        VariableExpander $expander,
        ?string $command = null,
        ?Output $output = null,
        bool $trimNewlines = true,
    ): string {
        if ($command === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'shell'", $expander->source);
        }
        if (trim($command) === '') {
            return '';
        }
        $result = new ExportingShell($shell, $expander->context->exports, $expander, $output)->capture($command);
        $expander->context->variables['.SHELLSTATUS'] = new Variable(
            '.SHELLSTATUS',
            (string) $result->status,
            false,
            'override',
        );
        $text = str_replace("\r\n", "\n", $result->output);
        return str_replace(
            "\n",
            ' ',
            $trimNewlines ? rtrim($text, "\n") : (str_ends_with($text, "\n") ? substr($text, 0, -1) : $text),
        );
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
     * Return the stored value of a variable without expanding it.
     *
     * Makefile:
     * ```makefile
     * greeting = hello $(name)
     * all: ; @printf '<%s>\n' '$(value greeting)'
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * <hello $(name)>
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function value(VariableExpander $expander, ?string $name = null): string
    {
        if ($name === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'value'");
        }
        return $expander->variable($name)->expression ?? '';
    }

    /**
     * Print a diagnostic with its source location and continue with an empty value.
     *
     * Makefile:
     * ```makefile
     * all: ; @echo $(warning check)done
     * ```
     *
     * Run:
     * ```text
     * $ ./phmake
     * Makefile:1: check
     * done
     * ```
     */
    public static function warning(string $message, ?Output $output, ?string $source = null): string
    {
        $output?->writeWarning($message, $source);
        return '';
    }

    /**
     * Expand each wildcard pattern into sorted existing paths.
     *
     * Makefile:
     * ```makefile
     * all: ; @echo $(wildcard Make*)
     * ```
     * Run (with only Makefile matching):
     * ```text
     * $ ./phmake
     * Makefile
     * ```
     *
     * @throws MakefileErrorException
     */
    public static function wildcard(Filesystem $files, ?string $patterns = null): string
    {
        if ($patterns === null) {
            throw new MakefileErrorException("insufficient number of arguments to function 'wildcard'");
        }
        $paths = [];
        foreach (self::splitWords($patterns) as $pattern) {
            $paths = [...$paths, ...$files->matching($pattern)];
        }
        return implode(' ', $paths);
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

    /**
     * @throws MakefileErrorException
     */
    private static function index(string $value, string $function, string $position, int $minimum): int
    {
        if (preg_match('/^[0-9]+$/D', trim($value)) !== 1) {
            throw new MakefileErrorException(
                "invalid $position argument to '$function' function: " . ($value === '' ? 'empty value' : "'$value'"),
            );
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
            if ($function === 'wordlist') {
                throw new MakefileErrorException("invalid $position argument to '$function' function: '$value'");
            }
            throw new MakefileErrorException("$position argument to '$function' function must be greater than 0");
        }
        return (int) $digits;
    }

    /**
     * @throws MakefileErrorException
     */
    private static function integer(string $text, string $position, ?string $source): string
    {
        $text = trim($text);
        if (preg_match('/^[+-]?[0-9]+$/D', $text) !== 1) {
            throw new MakefileErrorException(
                "non-numeric $position argument to 'intcmp' function: " . ($text === '' ? 'empty value' : "'$text'"),
                $source,
            );
        }
        $digits = ltrim(ltrim($text, '+-'), '0');
        return $digits === '' ? '0' : (str_starts_with($text, '-') ? '-' : '') . $digits;
    }

    /**
     * @return list<string>
     */
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
