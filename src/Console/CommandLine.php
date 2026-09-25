<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Override;
use Tamiroh\Phmake\Makefile\Evaluation\Assignment;
use Tamiroh\Phmake\Makefile\Evaluation\EvaluationContext;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\Execution\ExecutionOptions;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Parser\Configuration;

use function array_map;
use function array_values;
use function count;
use function implode;
use function in_array;
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

    public readonly InputOptions $input;

    public readonly ExecutionOptions $execution;

    public private(set) bool $version = false;

    public private(set) bool $noBuiltinRules = false;

    public private(set) bool $noBuiltinVariables = false;

    public private(set) bool $environmentOverrides = false;

    public private(set) ?bool $printDirectory = null;

    /** @var list<string> */
    public array $includeDirectories {
        get => $this->input->includes;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, Variable> $defaults
     *
     * @throws MakefileErrorException
     */
    public function __construct(
        array $arguments,
        string $makeflags = '',
        string $gnumakeflags = '',
        array $defaults = [],
    ) {
        $this->input = new InputOptions();
        $this->execution = new ExecutionOptions();
        $this->readFlags($gnumakeflags, $defaults);
        $this->readFlags($makeflags, $defaults);
        $this->readArguments($arguments, false, $defaults);
    }

    /**
     * @return list<string>
     */
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

    public function makeflags(?int $makefileRestart = null): string
    {
        $execution = $makefileRestart === null ? $this->execution : $this->execution->forMakefiles($makefileRestart);
        $assignments = [];
        foreach ($this->variables as $variable) {
            if ($variable->origin !== 'command line') {
                continue;
            }
            $assignments[] = str_replace(
                ['\\', '$', ' ', "\t", "\n"],
                ['\\\\', '$$', '\\ ', "\\\t", "\\\n"],
                $variable->name
                . ($variable->recursive ? '=' : ':=')
                . ($variable->recursive ? $variable->expression : str_replace('$', '$$', $variable->expression)),
            );
        }
        return (
            ($execution->alwaysMake ? 'B' : '')
            . ($this->environmentOverrides ? 'e' : '')
            . ($execution->ignoreErrors ? 'i' : '')
            . ($execution->keepGoing ? 'k' : '')
            . ($execution->dryRun ? 'n' : '')
            . ($execution->question ? 'q' : '')
            . ($this->noBuiltinRules ? 'r' : '')
            . ($this->noBuiltinVariables ? 'R' : '')
            . ($execution->silent ? 's' : '')
            . ($execution->touch ? 't' : '')
            . ($this->printDirectory === null ? '' : ($this->printDirectory ? 'w' : ' --no-print-directory'))
            . implode('', array_map(
                static fn(string $path): string => ' -I' . str_replace(['\\', ' '], ['\\\\', '\\ '], $path),
                $this->input->includes,
            ))
            . implode('', array_map(
                static fn(string $text): string => ' --eval='
                . str_replace(['\\', '$', ' ', "\t", "\n"], ['\\\\', '$$', '\\ ', "\\\t", "\\\n"], $text),
                $this->input->evaluations,
            ))
            . ($assignments === [] ? '' : ' -- ' . implode(' ', $assignments))
        );
    }

    /**
     * @param array<string, Variable> $variables
     *
     * @throws MakefileErrorException
     */
    #[Override]
    public function updateMakeflags(array &$variables, ?VariableExpander $expander = null): void
    {
        $this->readFlags(
            ($expander ?? new VariableExpander(array_values($variables)))->expand('$(MAKEFLAGS)'),
            $variables,
        );
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
     *
     * @throws MakefileErrorException
     */
    private function assign(string $argument, array $defaults): bool
    {
        $assignment = Assignment::parse($argument, allowWhitespace: true);
        if ($assignment === null) {
            return false;
        }
        $context = new EvaluationContext(array_values([...$defaults, ...$this->variables]));
        $context->shell = new Shell();
        $context->filesystem = new Filesystem();
        $variables = &$context->variables;
        $expander = new VariableExpander($context, new Output());
        $assignment = $assignment->resolveName($expander);
        $assignment->apply($variables, 'command line', expander: $expander);
        if (isset($variables['.SHELLSTATUS'])) {
            $this->variables['.SHELLSTATUS'] = $variables['.SHELLSTATUS'];
        }
        if ($variables[$assignment->name]->origin === 'command line') {
            $this->variables[$assignment->name] = $variables[$assignment->name];
        }
        return true;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, Variable> $defaults
     *
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
     *
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
     *
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
            '--include-dir' => '-I',
            '--eval' => '-E',
            '--just-print', '--dry-run', '--recon' => '-n',
            '--question' => '-q',
            '--touch' => '-t',
            '--always-make' => '-B',
            '--keep-going' => '-k',
            '--no-keep-going', '--stop' => '-S',
            '--ignore-errors' => '-i',
            '--assume-new', '--new-file', '--what-if' => '-W',
            '--assume-old', '--old-file' => '-o',
            default => $argument,
        };
        if ($argument === '--no-print-directory') {
            $this->printDirectory = false;
            return;
        }
        if ($argument === '--no-silent' || $argument === '--no-quiet') {
            $this->execution->silent = false;
            return;
        }
        foreach ([
            '--file=' => '-f',
            '--makefile=' => '-f',
            '--directory=' => '-C',
            '--include-dir=' => '-I',
            '--eval=' => '-E',
            '--assume-new=' => '-W',
            '--new-file=' => '-W',
            '--what-if=' => '-W',
            '--assume-old=' => '-o',
            '--old-file=' => '-o',
        ] as $prefix => $short) {
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
                case 'n':
                    $this->execution->dryRun = true;
                    break;
                case 'q':
                    $this->execution->question = true;
                    break;
                case 't':
                    $this->execution->touch = true;
                    break;
                case 'B':
                    $this->execution->alwaysMake = true;
                    break;
                case 'k':
                    $this->execution->keepGoing = true;
                    break;
                case 'S':
                    $this->execution->keepGoing = false;
                    break;
                case 'i':
                    $this->execution->ignoreErrors = true;
                    break;
                case 's':
                    $this->execution->silent = true;
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
                case 'I':
                case 'E':
                case 'W':
                case 'o':
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
                    if ($option === 'I') {
                        if ($path === '-') {
                            $this->input->includes = ['-'];
                        } elseif (!in_array($path, $this->input->includes, true)) {
                            $this->input->includes[] = $path;
                        }
                    } elseif ($option === 'E') {
                        $path = $inherited ? str_replace('$$', '$', $path) : $path;
                        if (!$inherited || !in_array($path, $this->input->evaluations, true)) {
                            $this->input->evaluations[] = $path;
                        }
                    } elseif (!$inherited) {
                        if ($option === 'W') {
                            $this->execution->newFiles[] = $path;
                        } elseif ($option === 'o') {
                            $this->execution->oldFiles[] = $path;
                        } elseif ($option === 'f') {
                            $this->input->makefiles[] = $path;
                        } else {
                            $this->input->directories[] = $path;
                        }
                    }
                    return;
                default:
                    throw new MakefileErrorException("Option `$argument' is not supported");
            }
        }
    }

    public function __clone(): void
    {
        $this->input = clone $this->input;
        $this->execution = clone $this->execution;
    }
}
