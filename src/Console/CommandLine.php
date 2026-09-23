<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;

use function count;
use function implode;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class CommandLine
{
    /** @var list<string> */
    public private(set) array $targets = [];

    /** @var array<string, Variable> */
    public private(set) array $variables = [];

    /** @var list<string> */
    public private(set) array $makefiles = [];

    public private(set) bool $silent = false;

    public private(set) bool $version = false;

    /**
     * @param list<string> $arguments
     * @throws MakefileErrorException
     */
    public function __construct(array $arguments, string $makeflags = '')
    {
        $assignments = false;
        foreach (self::splitFlags($makeflags) as $argument) {
            if ($argument === '--') {
                $assignments = true;
            } elseif ($assignments) {
                self::assign(str_replace('$$', '$', $argument), $this->variables);
            } elseif (
                $argument === '--silent'
                || $argument === '--quiet'
                || preg_match('/^-?[A-Za-z]*s[A-Za-z]*$/D', $argument) === 1
            ) {
                $this->silent = true;
            }
        }
        $options = true;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === '--' && $options) {
                $options = false;
            } elseif ($options && str_starts_with($argument, '-') && $argument !== '-') {
                $this->readOption($argument, $arguments, $index);
            } elseif (!self::assign($argument, $this->variables)) {
                $this->targets[] = $argument;
            }
        }
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
        return ($this->silent ? 's' : '') . ($assignments === [] ? '' : ' -- ' . implode(' ', $assignments));
    }

    /**
     * @param list<string> $arguments
     * @throws MakefileErrorException
     */
    private function readOption(string $argument, array $arguments, int &$index): void
    {
        if ($argument === '--silent' || $argument === '--quiet') {
            $this->silent = true;
            return;
        }
        if ($argument === '--version') {
            $this->version = true;
            return;
        }
        foreach (['--file=', '--makefile='] as $prefix) {
            if (str_starts_with($argument, $prefix)) {
                $path = substr($argument, strlen($prefix));
                if ($path === '') {
                    throw new MakefileErrorException('Option -f requires a file name');
                }
                $this->makefiles[] = $path;
                return;
            }
        }
        if ($argument === '--file' || $argument === '--makefile') {
            $argument = '-f';
        }
        for ($offset = 1; $offset < strlen($argument); $offset++) {
            switch ($argument[$offset]) {
                case 's':
                    $this->silent = true;
                    break;
                case 'v':
                    $this->version = true;
                    break;
                case 'f':
                    $path = substr($argument, $offset + 1);
                    if ($path === '') {
                        $path = $arguments[++$index] ?? '';
                    }
                    if ($path === '') {
                        throw new MakefileErrorException('Option -f requires a file name');
                    }
                    $this->makefiles[] = $path;
                    return;
                default:
                    throw new MakefileErrorException("Option `$argument' is not supported");
            }
        }
    }
}
