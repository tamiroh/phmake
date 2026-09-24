<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_values;
use function ltrim;
use function preg_match;
use function strpos;
use function substr;

final readonly class Assignment
{
    private function __construct(
        public string $name,
        private string $operator,
        private string $expression,
    ) {}

    public static function parse(string $text): ?self
    {
        $matches = [];
        if (preg_match('/^\s*([A-Za-z_.][A-Za-z0-9_.-]*)\s*(::=|:=|\+=|\?=|=)(.*)$/s', $text, $matches) !== 1) {
            return null;
        }
        /** @var array{string, non-empty-string, string, string} $matches */
        return new self($matches[1], $matches[2], ltrim($matches[3]));
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
    public function apply(array &$variables, string $origin, ?Output $output = null, ?string $source = null): void
    {
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
        $value = $recursive
            ? $this->expression
            : new VariableExpander(array_values($variables), $output, source: $source)->expand($this->expression);
        if ($this->operator === '+=' && $previous !== null) {
            $separator = $this->name === 'MAKEFLAGS' ? strpos($previous->expression, ' -- ') : false;
            $value =
                ($separator === false ? $previous->expression : substr($previous->expression, 0, $separator))
                . ' '
                . $value;
        }
        $variables[$this->name] = new Variable($this->name, $value, $recursive, $origin);
    }
}
