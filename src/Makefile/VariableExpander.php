<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use LogicException;

use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function ltrim;
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
        public int $callParameters = 0,
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

    /**
     * @param list<string> $arguments
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    public function invokeFunction(
        string $name,
        array $arguments,
        array $expanding = [],
        bool $argumentsExpanded = false,
    ): ?string {
        $maximum = Functions::ARGUMENT_COUNTS[$name] ?? null;
        if ($maximum === null) {
            return null;
        }
        $arguments = array_slice($arguments, 0, $maximum);
        if (in_array($name, ['if', 'and', 'or'], true)) {
            $expand = $argumentsExpanded
                ? static fn(string $argument): string => $argument
                : /** @throws MakefileErrorException */
                fn(string $argument): string => $this->expand($argument, $expanding);
            return match ($name) {
                'if' => Functions::if($expand, $arguments[0] ?? null, $arguments[1] ?? null, $arguments[2] ?? ''),
                'and' => Functions::and($arguments, $expand),
                'or' => Functions::or($arguments, $expand),
            };
        }
        if ($name === 'foreach') {
            return Functions::foreach(
                $this,
                $expanding,
                $arguments[0] ?? null,
                $arguments[1] ?? null,
                $arguments[2] ?? null,
            );
        }
        if (!$argumentsExpanded) {
            if ($name === 'info') {
                $arguments[0] = ltrim($arguments[0] ?? '');
            }
            foreach ($arguments as &$argument) {
                $argument = $this->expand($argument, $expanding);
            }
            unset($argument);
        }
        $first = $arguments[0] ?? null;
        $second = $arguments[1] ?? null;
        $third = $arguments[2] ?? null;
        return match ($name) {
            'call' => Functions::call($arguments, $this, $expanding),
            'info' => Functions::info($first ?? '', $this->output),
            'value' => Functions::value($this, $first),
            'flavor' => Functions::flavor($this, $first),
            'origin' => Functions::origin($this, $first),
            'subst' => Functions::subst($first, $second, $third),
            'patsubst' => Functions::patsubst($first, $second, $third),
            'strip' => Functions::strip($first),
            'findstring' => Functions::findstring($first, $second),
            'filter' => Functions::filter($first, $second),
            'filter-out' => Functions::filterOut($first, $second),
            'sort' => Functions::sort($first),
            'word' => Functions::word($first, $second),
            'wordlist' => Functions::wordlist($first, $second, $third),
            'words' => Functions::words($first),
            'firstword' => Functions::firstword($first),
            'lastword' => Functions::lastword($first),
            'addprefix' => Functions::addprefix($first, $second),
            'addsuffix' => Functions::addsuffix($first, $second),
            'join' => Functions::join($first, $second),
            'dir' => Functions::dir($first),
            'notdir' => Functions::notdir($first),
            'basename' => Functions::basename($first),
            'suffix' => Functions::suffix($first),
            default => throw new LogicException("Unknown function: $name"),
        };
    }

    public function variable(string $name): ?Variable
    {
        return $this->variables[$name] ?? null;
    }

    /** @param list<Variable> $variables */
    public function withVariables(array $variables, ?int $callParameters = null): self
    {
        return new self(
            [...array_values($this->variables), ...$variables],
            $this->output,
            $callParameters ?? $this->callParameters,
        );
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
            $argumentCount = Functions::ARGUMENT_COUNTS[$matches[1]] ?? null;
            if ($argumentCount !== null) {
                return (
                    $this->invokeFunction(
                        $matches[1],
                        $this->arguments(substr($reference, strlen($matches[0])), $argumentCount, $opening),
                        $expanding,
                    ) ?? ''
                );
            }
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
