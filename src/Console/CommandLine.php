<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Override;
use Random\RandomException;
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
use function ctype_digit;
use function implode;
use function in_array;
use function is_numeric;
use function ltrim;
use function random_int;
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

    #[Override]
    public private(set) bool $noBuiltinRules = false;

    public private(set) bool $noBuiltinVariables = false;

    public private(set) bool $environmentOverrides = false;

    public private(set) ?bool $printDirectory = null;

    /** @var list<string> */
    #[Override]
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
        $flags =
            ($execution->alwaysMake ? 'B' : '')
            . ($this->environmentOverrides ? 'e' : '')
            . ($execution->ignoreErrors ? 'i' : '')
            . ($execution->keepGoing ? 'k' : '')
            . ($execution->dryRun ? 'n' : '')
            . ($execution->question ? 'q' : '')
            . ($this->noBuiltinRules ? 'r' : '')
            . ($this->noBuiltinVariables ? 'R' : '')
            . ($execution->reporting->silent ? 's' : '')
            . ($execution->touch ? 't' : '')
            . ($this->printDirectory === true ? 'w' : '')
            . (
                $execution->parallel->jobs === 1
                    ? ''
                    : ' -j' . ($execution->parallel->jobs === 0 ? '' : $execution->parallel->jobs)
            )
            . ($execution->parallel->auth === null ? '' : ' --jobserver-auth=' . $execution->parallel->auth)
            . ($execution->parallel->sync === 'none' ? '' : ' -O' . $execution->parallel->sync)
            . ($execution->parallel->mutex === null ? '' : ' --sync-mutex=' . $execution->parallel->mutex)
            . ($execution->parallel->load === null ? '' : ' -l' . $execution->parallel->load)
            . ($execution->parallel->shuffle === null ? '' : ' --shuffle=' . $execution->parallel->shuffle)
            . implode('', array_map(
                static fn(string $levels): string => ' --debug=' . str_replace(' ', '\\ ', $levels),
                $execution->reporting->debugLevels,
            ))
            . ($execution->reporting->trace ? ' --trace' : '')
            . ($this->printDirectory === false ? ' --no-print-directory' : '')
            . implode('', array_map(
                static fn(string $path): string => ' -I' . str_replace(['\\', ' '], ['\\\\', '\\ '], $path),
                $this->input->includes,
            ))
            . implode('', array_map(
                static fn(string $text): string => ' --eval='
                . str_replace(['\\', '$', ' ', "\t", "\n"], ['\\\\', '$$', '\\ ', "\\\t", "\\\n"], $text),
                $this->input->evaluations,
            ))
            . ($assignments === [] ? '' : ' -- ' . implode(' ', $assignments));
        return $execution->parallel->jobs === 1 ? $flags : ltrim($flags);
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
                && $name !== '.FEATURES'
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
        $variable = $assignment->apply($variables, 'command line', expander: $expander);
        if (isset($variables['.SHELLSTATUS'])) {
            $this->variables['.SHELLSTATUS'] = $variables['.SHELLSTATUS'];
        }
        if ($variable->origin === 'command line') {
            $this->variables[$variable->name] = $variable;
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
            '--jobs' => '-j',
            '--output-sync' => '-O',
            '--load-average', '--max-load' => '-l',
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
        if ($argument === '--trace') {
            $this->execution->reporting->trace = true;
            return;
        }
        if (str_starts_with($argument, '--debug=')) {
            $this->execution->reporting->addDebugFlags(substr($argument, 8));
            return;
        }
        if ($argument === '--shuffle' || str_starts_with($argument, '--shuffle=')) {
            $shuffle = $argument === '--shuffle' ? 'random' : substr($argument, 10);
            if ($shuffle === 'random') {
                try {
                    $shuffle = (string) random_int(0, 2147483647);
                } catch (RandomException $error) {
                    throw new MakefileErrorException($error->getMessage());
                }
            }
            if (!in_array($shuffle, ['none', 'identity', 'reverse'], true) && !ctype_digit($shuffle)) {
                throw new MakefileErrorException("invalid shuffle mode: '$shuffle'");
            }
            $this->execution->parallel->shuffle = in_array($shuffle, ['none', 'identity'], true) ? null : $shuffle;
            return;
        }
        if (str_starts_with($argument, '--sync-mutex=')) {
            $this->execution->parallel->mutex = substr($argument, 13);
            return;
        }
        if (str_starts_with($argument, '--jobserver-auth=')) {
            $this->execution->parallel->auth = substr($argument, 17);
            return;
        }
        if (str_starts_with($argument, '--jobserver-style=')) {
            $style = substr($argument, 18);
            if (!in_array($style, ['fifo', 'pipe'], true)) {
                throw new MakefileErrorException("unknown jobserver auth style '$style'");
            }
            $this->execution->parallel->style = $style;
            return;
        }
        if ($argument === '--no-print-directory') {
            $this->printDirectory = false;
            return;
        }
        if ($argument === '--no-silent' || $argument === '--no-quiet') {
            $this->execution->reporting->silent = false;
            return;
        }
        foreach ([
            '--jobs=' => '-j',
            '--output-sync=' => '-O',
            '--load-average=' => '-l',
            '--max-load=' => '-l',
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
                case 'O':
                    $sync = substr($argument, $offset + 1);
                    $sync = $sync === '' ? 'target' : $sync;
                    if (!in_array($sync, ['none', 'line', 'target', 'recurse'], true)) {
                        throw new MakefileErrorException("unknown output-sync type '$sync'");
                    }
                    $this->execution->parallel->sync = $sync;
                    return;
                case 'l':
                    $load = substr($argument, $offset + 1);
                    if ($load === '' && isset($arguments[$index + 1]) && is_numeric($arguments[$index + 1])) {
                        $load = $arguments[$index + 1];
                        $index++;
                    }
                    if ($load !== '' && (!is_numeric($load) || (float) $load < 0)) {
                        throw new MakefileErrorException('The -l option requires a nonnegative number');
                    }
                    $this->execution->parallel->load = $load === '' ? null : (float) $load;
                    return;
                case 'j':
                    $jobs = substr($argument, $offset + 1);
                    if ($jobs === '' && isset($arguments[$index + 1]) && ctype_digit($arguments[$index + 1])) {
                        $jobs = $arguments[$index + 1];
                        $index++;
                    }
                    if ($jobs !== '' && (!ctype_digit($jobs) || (int) $jobs < 1)) {
                        throw new MakefileErrorException('The -j option requires a positive integer argument');
                    }
                    $this->execution->parallel->jobs = $inherited && $this->execution->parallel->commandJobs !== null
                        ? $this->execution->parallel->commandJobs
                        : ($jobs === '' ? 0 : (int) $jobs);
                    if (!$inherited) {
                        $this->execution->parallel->reset = $this->execution->parallel->auth !== null;
                        $this->execution->parallel->commandJobs = $this->execution->parallel->jobs;
                        $this->execution->parallel->auth = null;
                    }
                    return;
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
                    $this->execution->reporting->silent = true;
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
