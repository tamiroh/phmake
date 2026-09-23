<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Variable;

use function implode;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_replace;
use function strlen;

final readonly class CommandLine
{
    /** @var list<string> */
    public array $targets;

    /** @var array<string, Variable> */
    public array $variables;

    /** @param list<string> $arguments */
    public function __construct(array $arguments, string $makeflags = '')
    {
        $variables = [];
        $targets = [];
        $assignments = false;
        foreach (self::splitFlags($makeflags) as $argument) {
            if ($argument === '--') {
                $assignments = true;
            } elseif ($assignments) {
                self::assign(str_replace('$$', '$', $argument), $variables);
            }
        }
        foreach ($arguments as $argument) {
            if (!self::assign($argument, $variables)) {
                $targets[] = $argument;
            }
        }
        $this->targets = $targets;
        $this->variables = $variables;
    }

    /** @param array<string, Variable> $variables */
    private static function assign(string $argument, array &$variables): bool
    {
        $matches = [];
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_.-]*)=(.*)$/s', $argument, $matches) !== 1) {
            return false;
        }
        /** @var array{non-empty-string, non-empty-string, string} $matches */
        $variables[$matches[1]] = new Variable($matches[1], ltrim($matches[2]));
        return true;
    }

    /** @return list<string> */
    private static function splitFlags(string $flags): array
    {
        $words = [];
        $word = '';
        for ($index = 0; $index < strlen($flags); $index++) {
            if ($flags[$index] === '\\' && ($index + 1) < strlen($flags)) {
                $word .= $flags[++$index];
            } elseif (str_contains(" \t\n\r\v\f", $flags[$index])) {
                if ($word !== '') {
                    $words[] = $word;
                    $word = '';
                }
            } else {
                $word .= $flags[$index];
            }
        }
        if ($word !== '') {
            $words[] = $word;
        }
        return $words;
    }

    public function makeflags(): string
    {
        $assignments = [];
        foreach ($this->variables as $variable) {
            $assignments[] = str_replace(
                ['\\', '$', ' ', "\t", "\n"],
                ['\\\\', '$$', '\\ ', "\\\t", "\\\n"],
                $variable->name . '=' . $variable->expression,
            );
        }
        return $assignments === [] ? '' : ' -- ' . implode(' ', $assignments);
    }
}
