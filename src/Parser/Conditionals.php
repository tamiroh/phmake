<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\VariableExpander;

use function array_key_last;
use function array_pop;
use function in_array;
use function ltrim;
use function preg_match;
use function rtrim;
use function strlen;
use function strpos;
use function substr;
use function trim;

final class Conditionals
{
    /** @var list<array{parent: bool, active: bool, matched: bool, else: bool}> */
    private array $stack = [];

    public function active(): bool
    {
        $index = array_key_last($this->stack);
        return $index === null || $this->stack[$index]['active'];
    }

    public function finish(int $lineNumber): void
    {
        if ($this->stack !== []) {
            throw new ParseException($lineNumber, "missing 'endif'");
        }
    }

    /** @throws MakefileErrorException */
    public function read(string $line, VariableExpander $expander, int $lineNumber): bool
    {
        $matches = [];
        if (preg_match('/^\s*(ifdef|ifndef|ifeq|ifneq|else|endif)(?:[ \t]+|$)(.*)$/s', $line, $matches) !== 1) {
            return false;
        }
        /** @var array{string, string, string} $matches */
        [, $directive, $argument] = $matches;
        if (preg_match('/^\s*(?::=|\+=|\?=|=)/', $argument) === 1) {
            return false;
        }
        $argument = trim($argument);
        if ($directive === 'endif') {
            if ($this->stack === []) {
                throw new ParseException($lineNumber, "extraneous 'endif'");
            }
            if ($argument !== '') {
                throw new ParseException($lineNumber, "extraneous text after 'endif'");
            }
            array_pop($this->stack);
            return true;
        }
        if ($directive === 'else') {
            $index = array_key_last($this->stack);
            if ($index === null) {
                throw new ParseException($lineNumber, "extraneous 'else'");
            }
            $frame = $this->stack[$index];
            if ($frame['else']) {
                throw new ParseException($lineNumber, "only one 'else' per conditional");
            }
            $eligible = $frame['parent'] && !$frame['matched'];
            if ($argument === '') {
                $frame['active'] = $eligible;
                $frame['else'] = true;
            } else {
                if (preg_match('/^(ifdef|ifndef|ifeq|ifneq)[ \t]+(.*)$/s', $argument, $matches) !== 1) {
                    throw new ParseException($lineNumber, "extraneous text after 'else'");
                }
                /** @var array{string, string, string} $matches */
                $frame['active'] = $eligible && $this->evaluate($matches[1], trim($matches[2]), $expander, $lineNumber);
            }
            $frame['matched'] = $frame['matched'] || $frame['active'];
            $this->stack[$index] = $frame;
            return true;
        }
        $parent = $this->active();
        $active = $parent && $this->evaluate($directive, $argument, $expander, $lineNumber);
        $this->stack[] = ['parent' => $parent, 'active' => $active, 'matched' => $active, 'else' => false];
        return true;
    }

    /** @return array{string, string} */
    private function comparison(string $argument, int $lineNumber): array
    {
        if (($argument[0] ?? '') === '(') {
            $depth = 0;
            $comma = null;
            for ($index = 1; $index < strlen($argument); $index++) {
                if ($argument[$index] === '(') {
                    $depth++;
                } elseif ($argument[$index] === ')') {
                    if ($depth === 0) {
                        if ($comma !== null && trim(substr($argument, $index + 1)) === '') {
                            return [
                                rtrim(substr($argument, 1, $comma - 1), " \t"),
                                ltrim(substr($argument, $comma + 1, $index - $comma - 1), " \t"),
                            ];
                        }
                        break;
                    }
                    $depth--;
                } elseif ($argument[$index] === ',' && $depth === 0 && $comma === null) {
                    $comma = $index;
                }
            }
        } elseif (in_array($argument[0] ?? '', ["'", '"'], true)) {
            $end = strpos($argument, $argument[0], 1);
            if ($end !== false) {
                $left = substr($argument, 1, $end - 1);
                $rest = ltrim(substr($argument, $end + 1));
                if (in_array($rest[0] ?? '', ["'", '"'], true)) {
                    $end = strpos($rest, $rest[0], 1);
                    if ($end !== false && trim(substr($rest, $end + 1)) === '') {
                        return [$left, substr($rest, 1, $end - 1)];
                    }
                }
            }
        }
        throw new ParseException($lineNumber, 'invalid syntax in conditional');
    }

    /** @throws MakefileErrorException */
    private function evaluate(string $directive, string $argument, VariableExpander $expander, int $lineNumber): bool
    {
        if ($directive === 'ifdef' || $directive === 'ifndef') {
            $name = trim($expander->expand($argument));
            if ($name === '' || preg_match('/\s/', $name) === 1) {
                throw new ParseException($lineNumber, 'invalid syntax in conditional');
            }
            $defined = ($expander->variable($name)->expression ?? '') !== '';
            return $directive === 'ifdef' ? $defined : !$defined;
        }
        [$left, $right] = $this->comparison($argument, $lineNumber);
        $equal = $expander->expand($left) === $expander->expand($right);
        return $directive === 'ifeq' ? $equal : !$equal;
    }
}
