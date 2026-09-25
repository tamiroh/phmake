<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use LogicException;

use function array_values;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function strspn;
use function substr;
use function trim;

final readonly class Assignment
{
    public function __construct(
        public string $name,
        public string $operator,
        public string $expression,
    ) {}

    public static function parse(string $text, bool $allowWhitespace = false): ?self
    {
        $text = ltrim($text);
        $depth = 0;
        for ($index = 0; $index < strlen($text); $index++) {
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
                if (preg_match('/^(:::=|::=|:=|!=|\+=|\?=|=)/', substr($text, $index), $matches) === 1) {
                    /** @var array{non-falsy-string, ':::='|'::='|':='|'!='|'+='|'?='|'='} $matches */
                    return new self(
                        trim(substr($text, 0, $index)),
                        $matches[1],
                        ltrim(substr($text, $index + strlen($matches[1]))),
                    );
                }
                if ($text[$index] === ':' || $text[$index] === '#') {
                    return null;
                }
            }
        }
        return null;
    }

    /** @param array<string, Variable> $variables */
    public static function undefine(array &$variables, string $name, string $origin): void
    {
        if (isset($variables[$name]) && self::priority($variables[$name]->origin) <= self::priority($origin)) {
            unset($variables[$name]);
        }
    }

    private static function priority(string $origin): int
    {
        return match ($origin) {
            'default' => 0,
            'environment' => 1,
            'file' => 2,
            'environment override' => 3,
            'command line' => 4,
            'override' => 5,
            'automatic' => 6,
            default => 0,
        };
    }

    /**
     * @param array<string, Variable> $variables
     * @throws MakefileErrorException
     */
    public function apply(
        array &$variables,
        string $origin,
        ?Output $output = null,
        ?string $source = null,
        ?VariableExpander $expander = null,
        bool $private = false,
    ): void {
        $shellValue = null;
        if ($this->operator === '!=') {
            $scope = $expander ?? new VariableExpander(array_values($variables), $output, source: $source);
            $shellValue = Functions::shell(
                $scope->context->shell ?? throw new LogicException('Missing shell service'),
                $scope,
                $scope->expand($this->expression),
                $output,
                false,
            );
        }
        $previous = $variables[$this->name] ?? null;
        if (
            $previous !== null && self::priority($previous->origin) > self::priority($origin)
            || $this->operator === '?=' && $previous !== null
        ) {
            return;
        }
        $recursive = $this->operator === '+='
            ? $previous->recursive ?? true
            : $this->operator !== ':=' && $this->operator !== '::=';
        $value = $recursive && $this->operator !== ':::='
            ? $this->expression
            : ($expander ?? new VariableExpander(
                array_values($variables),
                $output,
                source: $source,
            ))->expand($this->expression);
        if ($shellValue !== null) {
            $value = $shellValue;
        }
        if ($this->operator === ':::=') {
            $value = str_replace('$', '$$', $value);
        }
        if ($this->operator === '+=' && $previous !== null) {
            if ($value === '') {
                return;
            }
            $separator = $this->name === 'MAKEFLAGS' ? strpos($previous->expression, ' -- ') : false;
            $before = $separator === false ? $previous->expression : substr($previous->expression, 0, $separator);
            $value = ($before === '' ? '' : $before . ' ') . $value;
        }
        $variables[$this->name] = new Variable($this->name, $value, $recursive, $origin, $source, $private);
    }

    /** @throws MakefileErrorException */
    public function resolveName(VariableExpander $expander): self
    {
        $name = trim($expander->expand($this->name));
        if ($name === '') {
            throw new MakefileErrorException('empty variable name', $expander->source);
        }
        return new self($name, $this->operator, $this->expression);
    }
}
