<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function explode;
use function implode;
use function in_array;
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
    public function __construct(array $variables)
    {
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
                $result .= $this->reference($this->readReference($expression, $index), $expanding);
            } elseif ($next !== '') {
                $result .= $this->value($next, $expanding);
            }
        }
        return $result;
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
    private function reference(string $reference, array $expanding): string
    {
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
