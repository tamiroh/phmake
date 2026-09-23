<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function ltrim;
use function max;
use function preg_match;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

final readonly class VariableExpander
{
    /** @var array<string, Variable> */
    private array $variables;

    /** @param list<Variable> $variables */
    public function __construct(
        array $variables,
        private ?Output $output = null,
        private int $callParameters = 0,
    ) {
        $indexed = [];
        foreach ($variables as $variable) {
            $indexed[$variable->name] = $variable;
        }
        $this->variables = $indexed;
    }

    /**
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    public function expand(string $expression, array $expanding = []): string
    {
        $result = '';
        for ($index = 0; $index < strlen($expression); $index++) {
            if ($expression[$index] !== '$') {
                $result .= $expression[$index];
                continue;
            }
            $next = $expression[++$index] ?? '';
            if ($next === '$') {
                $result .= '$';
            } elseif ($next === '(' || $next === '{') {
                $result .= $this->reference($this->readReference($expression, $index), $expanding, $next);
            } elseif ($next !== '') {
                $result .= $this->value($next, $expanding);
            }
        }
        return $result;
    }

    /** @return list<string> */
    private function arguments(string $text, int $limit, string $opening): array
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
     * @param list<string> $arguments
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    private function call(array $arguments, array $expanding): string
    {
        $name = trim($arguments[0] ?? '');
        $arguments[0] = $name;
        $argumentCount = Functions::argumentCount($name);
        if ($argumentCount !== null) {
            return Functions::expand($name, array_slice($arguments, 1, $argumentCount));
        }
        if (in_array($name, ['if', 'and', 'or'], strict: true)) {
            return ConditionalFunctions::expand(
                $name,
                array_slice($arguments, 1, $name === 'if' ? 3 : null),
                static fn(string $argument): string => $argument,
            );
        }
        if ($name === 'info') {
            $this->output?->write(($arguments[1] ?? '') . "\n");
            return '';
        }
        if ($name === 'call') {
            return $this->call(array_slice($arguments, 1), $expanding);
        }
        $variable = $this->variables[$name] ?? null;
        if ($variable === null) {
            return '';
        }
        if (!$variable->recursive) {
            return $variable->expression;
        }
        $variables = $this->variables;
        $parameters = max($this->callParameters, count($arguments) - 1);
        for ($index = 0; $index <= $parameters; $index++) {
            $variables[(string) $index] = new Variable((string) $index, $arguments[$index] ?? '', false);
        }
        return new self(array_values($variables), $this->output, $parameters)->expand(
            $variable->expression,
            array_values(array_filter($expanding, static fn(string $variable): bool => $variable !== $name)),
        );
    }

    /** @throws MakefileErrorException */
    private function readReference(string $expression, int &$index): string
    {
        $opening = $expression[$index];
        $closing = $opening === '(' ? ')' : '}';
        $start = ++$index;
        $depth = 1;
        while ($index < strlen($expression)) {
            if ($expression[$index] === $opening) {
                $depth++;
            } elseif ($expression[$index] === $closing && --$depth === 0) {
                return substr($expression, $start, $index - $start);
            }
            $index++;
        }
        throw new MakefileErrorException('Unterminated variable reference');
    }

    /**
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    private function reference(string $reference, array $expanding, string $opening): string
    {
        $matches = [];
        if (preg_match('/^([a-z-]+)[ \t\n]+/', $reference, $matches) === 1) {
            /** @var array{non-empty-string, non-empty-string} $matches */
            $argumentCount = match ($matches[1]) {
                'if' => 3,
                'and', 'or', 'call' => PHP_INT_MAX,
                default => Functions::argumentCount($matches[1]),
            };
            if ($argumentCount !== null) {
                $arguments = $this->arguments(substr($reference, strlen($matches[0])), $argumentCount, $opening);
                if (in_array($matches[1], ['if', 'and', 'or'], strict: true)) {
                    return ConditionalFunctions::expand(
                        $matches[1],
                        $arguments,
                        /** @throws MakefileErrorException */
                        fn(string $argument): string => $this->expand($argument, $expanding),
                    );
                }
                foreach ($arguments as &$argument) {
                    $argument = $this->expand($argument, $expanding);
                }
                unset($argument);
                if ($matches[1] === 'call') {
                    return $this->call($arguments, $expanding);
                }
                return Functions::expand($matches[1], $arguments);
            }
        }
        if (preg_match('/^info[ \t\n]/', $reference) === 1) {
            $message = $this->expand(ltrim(substr($reference, 5)), $expanding);
            $this->output?->write($message . "\n");
            return '';
        }
        $reference = $this->expand($reference, $expanding);
        $colon = strpos($reference, ':');
        if ($colon === false || !str_contains(substr($reference, $colon + 1), '=')) {
            return $this->value($reference, $expanding);
        }
        [$from, $to] = explode('=', substr($reference, $colon + 1), 2);
        $words = preg_split(
            '/\s+/',
            trim($this->value(substr($reference, 0, $colon), $expanding)),
            -1,
            PREG_SPLIT_NO_EMPTY,
        );
        $result = [];
        foreach ($words === false ? [] : $words as $word) {
            if (str_contains($from, '%')) {
                $stem = new Pattern($from)->match($word);
                $result[] = $stem === null ? $word : new Pattern($to)->substitute($stem);
            } else {
                $result[] = str_ends_with($word, $from) ? substr($word, 0, strlen($word) - strlen($from)) . $to : $word;
            }
        }
        return implode(' ', $result);
    }

    /**
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    private function value(string $name, array $expanding): string
    {
        $variable = $this->variables[$name] ?? null;
        if ($variable === null) {
            return '';
        }
        if (!$variable->recursive) {
            return $variable->expression;
        }
        if (in_array($name, $expanding, true)) {
            throw new MakefileErrorException("Recursive variable `{$name}'");
        }
        return $this->expand($variable->expression, [...$expanding, $name]);
    }
}
