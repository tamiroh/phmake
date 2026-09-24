<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Assignment;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;
use Tamiroh\Phmake\Parser\Configuration;

use function array_values;
use function count;
use function implode;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class CommandLine implements Configuration
{
    /** @var list<string> */
    public private(set) array $targets = [];

    /** @var array<string, Variable> */
    public private(set) array $variables = [];

    /** @var list<string> */
    public private(set) array $makefiles = [];

    public private(set) bool $silent = false;

    public private(set) bool $version = false;

    public private(set) bool $noBuiltinRules = false;

    public private(set) bool $noBuiltinVariables = false;

    public private(set) bool $environmentOverrides = false;

    public private(set) ?bool $printDirectory = null;

    /** @var list<string> */
    public private(set) array $directories = [];

    /**
     * @param list<string> $arguments
     * @param array<string, Variable> $defaults
     * @throws MakefileErrorException
     */
    public function __construct(
        array $arguments,
        string $makeflags = '',
        string $gnumakeflags = '',
        array $defaults = [],
    ) {
        $this->readFlags($gnumakeflags, $defaults);
        $this->readFlags($makeflags, $defaults);
        $this->readArguments($arguments, false, $defaults);
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
                $variable->name
                . ($variable->recursive ? '=' : ':=')
                . ($variable->recursive ? $variable->expression : str_replace('$', '$$', $variable->expression)),
            );
        }
        return (
            ($this->environmentOverrides ? 'e' : '')
            . ($this->noBuiltinRules ? 'r' : '')
            . ($this->noBuiltinVariables ? 'R' : '')
            . ($this->silent ? 's' : '')
            . ($this->printDirectory === null ? '' : ($this->printDirectory ? 'w' : ' --no-print-directory'))
            . ($assignments === [] ? '' : ' -- ' . implode(' ', $assignments))
        );
    }

    /**
     * @param array<string, Variable> $variables
     * @throws MakefileErrorException
     */
    #[\Override]
    public function updateMakeflags(array &$variables): void
    {
        $this->readFlags(new VariableExpander(array_values($variables))->expand('$(MAKEFLAGS)'), $variables);
        foreach ($this->variables as $name => $variable) {
            if (($variables[$name]->origin ?? '') !== 'override') {
                $variables[$name] = $variable;
            }
        }
        foreach ($variables as $name => $variable) {
            if (
                $this->noBuiltinVariables
                && $variable->origin === 'default'
                && $name !== 'SHELL'
                && $name !== 'MAKE'
                && $name !== 'MAKECMDGOALS'
            ) {
                unset($variables[$name]);
            } elseif ($this->environmentOverrides && $variable->origin === 'environment' && $name !== 'MAKEFLAGS') {
                $variables[$name] = new Variable(
                    $name,
                    $variable->expression,
                    $variable->recursive,
                    'environment override',
                );
            }
        }
        $variables['MAKEFLAGS'] = new Variable(
            'MAKEFLAGS',
            $this->makeflags(),
            false,
            $variables['MAKEFLAGS']->origin ?? 'file',
        );
    }

    /**
     * @param array<string, Variable> $defaults
     * @throws MakefileErrorException
     */
    private function assign(string $argument, array $defaults): bool
    {
        $assignment = Assignment::parse($argument);
        if ($assignment === null) {
            return false;
        }
        $variables = [...$defaults, ...$this->variables];
        $assignment->apply($variables, 'command line');
        if ($variables[$assignment->name]->origin === 'command line') {
            $this->variables[$assignment->name] = $variables[$assignment->name];
        }
        return true;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, Variable> $defaults
     * @throws MakefileErrorException
     */
    private function readArguments(array $arguments, bool $inherited, array $defaults): void
    {
        $options = true;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === '--' && $options) {
                $options = false;
            } elseif ($options && str_starts_with($argument, '-') && $argument !== '-') {
                $this->readOption($argument, $arguments, $index, $inherited);
            } elseif (
                !$this->assign($inherited ? str_replace('$$', '$', $argument) : $argument, $defaults) && !$inherited
            ) {
                $this->targets[] = $argument;
            }
        }
    }

    /**
     * @param array<string, Variable> $defaults
     * @throws MakefileErrorException
     */
    private function readFlags(string $flags, array $defaults): void
    {
        $arguments = self::splitFlags($flags);
        if (isset($arguments[0]) && !str_starts_with($arguments[0], '-') && !str_contains($arguments[0], '=')) {
            $arguments[0] = '-' . $arguments[0];
        }
        $this->readArguments($arguments, true, $defaults);
    }

    /**
     * @param list<string> $arguments
     * @throws MakefileErrorException
     */
    private function readOption(string $argument, array $arguments, int &$index, bool $inherited): void
    {
        $argument = match ($argument) {
            '--silent', '--quiet' => '-s',
            '--version' => '-v',
            '--no-builtin-rules' => '-r',
            '--no-builtin-variables' => '-R',
            '--environment-overrides' => '-e',
            '--print-directory' => '-w',
            '--file', '--makefile' => '-f',
            '--directory' => '-C',
            default => $argument,
        };
        if ($argument === '--no-print-directory') {
            $this->printDirectory = false;
            return;
        }
        if ($argument === '--no-silent' || $argument === '--no-quiet') {
            $this->silent = false;
            return;
        }
        foreach (['--file=' => '-f', '--makefile=' => '-f', '--directory=' => '-C'] as $prefix => $short) {
            if (str_starts_with($argument, $prefix)) {
                if ($argument === $prefix) {
                    throw new MakefileErrorException(
                        "Option $short requires " . ($short === '-f' ? 'a file name' : 'a directory'),
                    );
                }
                $argument = $short . substr($argument, strlen($prefix));
                break;
            }
        }
        for ($offset = 1; $offset < strlen($argument); $offset++) {
            switch ($argument[$offset]) {
                case 's':
                    $this->silent = true;
                    break;
                case 'v':
                    $this->version = true;
                    break;
                case 'r':
                    $this->noBuiltinRules = true;
                    break;
                case 'R':
                    $this->noBuiltinVariables = true;
                    $this->noBuiltinRules = true;
                    break;
                case 'e':
                    $this->environmentOverrides = true;
                    break;
                case 'w':
                    $this->printDirectory = true;
                    break;
                case 'f':
                case 'C':
                    $option = $argument[$offset];
                    $path = substr($argument, $offset + 1);
                    if ($path === '') {
                        $path = $arguments[++$index] ?? '';
                    }
                    if ($path === '') {
                        throw new MakefileErrorException(
                            "Option -$option requires " . ($option === 'f' ? 'a file name' : 'a directory'),
                        );
                    }
                    if (!$inherited) {
                        if ($option === 'f') {
                            $this->makefiles[] = $path;
                        } else {
                            $this->directories[] = $path;
                        }
                    }
                    return;
                default:
                    throw new MakefileErrorException("Option `$argument' is not supported");
            }
        }
    }
}
