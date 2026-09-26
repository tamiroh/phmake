<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Override;
use Random\RandomException;
use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Evaluation\Assignment;
use Tamiroh\Phmake\Makefile\Evaluation\EvaluationContext;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\Execution\ExecutionOptions;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Parser\Configuration;

use function array_values;
use function count;
use function ctype_digit;
use function getcwd;
use function getenv;
use function in_array;
use function is_numeric;
use function putenv;
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

    public readonly ReversibleOptions $switches;

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
        $this->input->arguments = $arguments;
        $this->input->directory = (string) getcwd();
        $this->input->restarts = (int) getenv('MAKE_RESTARTS');
        putenv('MAKE_RESTARTS');
        $this->switches = new ReversibleOptions();
        $this->execution = new ExecutionOptions();
        // Inherited flags have command-line priority, but actual arguments are read last.
        $this->readFlags($gnumakeflags, $defaults, 'command line');
        if ($this->execution->reporting->warnUndefinedVariables && !isset($defaults['MAKEFLAGS'])) {
            new Output()->writeWarning("warning: undefined variable 'MAKEFLAGS'");
        }
        $this->readFlags($makeflags, $defaults, 'command line');
        $this->readArguments($arguments, false, $defaults, 'command line');
        $this->noBuiltinRules = $this->noBuiltinRules || $this->noBuiltinVariables;
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

    /**
     * @param array<string, Variable> $variables
     *
     * @throws MakefileErrorException
     */
    #[Override]
    public function finishReading(array &$variables, VariableExpander $expander): void
    {
        if (isset($variables['GNUMAKEFLAGS'])) {
            $this->readFlags($expander->expand('$(GNUMAKEFLAGS)'), $variables, 'environment');
        }
        $variables['GNUMAKEFLAGS'] = new Variable('GNUMAKEFLAGS', '', false, 'override');
        $this->updateMakeflags($variables, $expander, 'environment');
        $this->noBuiltinRules = $this->noBuiltinRules || $this->noBuiltinVariables;
        foreach ($variables as $name => $variable) {
            if (
                $this->noBuiltinVariables
                && $variable->origin === 'default'
                && !in_array($name, Builtins::INTERNAL_VARIABLES, true)
            ) {
                unset($variables[$name]);
            }
        }
        if ($this->noBuiltinRules) {
            new Assignment('SUFFIXES', ':=', '')->apply($variables, 'default');
        }
        MakeFlags::define($this, $variables, $expander->context->posix);
    }

    /**
     * @param array<string, Variable> $variables
     *
     * @throws MakefileErrorException
     */
    #[Override]
    public function updateMakeflags(
        array &$variables,
        ?VariableExpander $expander = null,
        string $origin = 'file',
    ): void {
        $this->readFlags(
            ($expander ?? new VariableExpander(array_values($variables)))->expand('$(MAKEFLAGS)'),
            $variables,
            $origin,
        );
        if ($expander?->output instanceof Output) {
            $expander->output->silent = $this->execution->reporting->silent;
            if ($this->switches->value('printDirectory') === true && $expander->output->directory === null) {
                $expander->output->writeDirectory(true, (string) getcwd());
            } elseif ($this->switches->value('printDirectory') === false) {
                $expander->output->directory = null;
                $expander->output->buffer->directory = null;
            }
        }
        foreach ($variables as $name => $variable) {
            if ($this->environmentOverrides && $variable->origin === 'environment') {
                $variables[$name] = new Variable(
                    $name,
                    $variable->expression,
                    $variable->recursive,
                    'environment',
                    environmentOverrides: true,
                );
            }
        }
        MakeFlags::define($this, $variables, $expander?->context->posix ?? false);
    }

    /**
     * @param array<string, Variable> $defaults
     *
     * @throws MakefileErrorException
     */
    private function assign(string $argument, array &$defaults, string $origin): bool
    {
        $assignment = Assignment::parse($argument, allowWhitespace: true);
        if ($assignment === null) {
            return false;
        }
        $context = new EvaluationContext(array_values(
            $origin === 'command line' ? [...$defaults, ...$this->variables] : $defaults,
        ));
        $context->shell = new Shell();
        $context->reporting = $this->execution->reporting;
        $context->filesystem = new Filesystem();
        $variables = &$context->variables;
        $expander = new VariableExpander($context, new Output());
        $assignment = $assignment->resolveName($expander);
        $variable = $assignment->apply($variables, $origin, expander: $expander);
        if (isset($variables['.SHELLSTATUS'])) {
            $defaults['.SHELLSTATUS'] = $variables['.SHELLSTATUS'];
            if ($origin === 'command line') {
                $this->variables['.SHELLSTATUS'] = $variables['.SHELLSTATUS'];
            }
        }
        $defaults[$variable->name] = $variable;
        if ($origin === 'command line' && $variable->origin === 'command line') {
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
    private function readArguments(array $arguments, bool $inherited, array &$defaults, string $origin): void
    {
        $options = true;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === '--' && $options) {
                $options = false;
            } elseif ($options && str_starts_with($argument, '-') && $argument !== '-') {
                $this->readOption($argument, $arguments, $index, $inherited, $origin);
            } elseif (
                !$this->assign(
                    $inherited && $origin === 'command line' ? str_replace('$$', '$', $argument) : $argument,
                    $defaults,
                    $origin,
                )
                && !$inherited
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
    private function readFlags(string $flags, array &$defaults, string $origin = 'file'): void
    {
        $arguments = self::splitFlags($flags);
        if (isset($arguments[0]) && !str_starts_with($arguments[0], '-') && !str_contains($arguments[0], '=')) {
            $arguments[0] = '-' . $arguments[0];
        }
        $this->readArguments($arguments, true, $defaults, $origin);
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MakefileErrorException
     */
    private function readOption(string $argument, array $arguments, int &$index, bool $inherited, string $origin): void
    {
        $argument = match ($argument) {
            '--jobs' => '-j',
            '--output-sync' => '-O',
            '--load-average', '--max-load' => '-l',
            '--silent', '--quiet' => '-s',
            '--version' => '-v',
            '--help' => '-h',
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
            '--check-symlink-times' => '-L',
            default => $argument,
        };
        if (str_starts_with($argument, '--temp-stdin=')) {
            $this->input->temporaryStdin = substr($argument, 13);
            return;
        }
        if ($argument === '--warn-undefined-variables') {
            $this->execution->reporting->warnUndefinedVariables = true;
            return;
        }
        if ($argument === '--trace') {
            $this->execution->reporting->trace = true;
            return;
        }
        if ($argument === '--debug') {
            $this->execution->reporting->addDebugFlags('basic');
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
            $this->switches->set('printDirectory', false, $origin);
            return;
        }
        if ($argument === '--no-silent' || $argument === '--no-quiet') {
            $this->execution->reporting->silent = $this->switches->set('silent', false, $origin);
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
                    $this->execution->parallel->syncSpecified = true;
                    return;
                case 'h':
                    $this->input->help = true;
                    break;
                case 'L':
                    $this->execution->files->checkSymlinkTimes = true;
                    break;
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
                case 'd':
                    $this->execution->reporting->debugAll = true;
                    break;
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
                    $this->execution->keepGoing = $this->switches->set('keepGoing', true, $origin);
                    break;
                case 'S':
                    $this->execution->keepGoing = $this->switches->set('keepGoing', false, $origin);
                    break;
                case 'i':
                    $this->execution->ignoreErrors = true;
                    break;
                case 's':
                    $this->execution->reporting->silent = $this->switches->set('silent', true, $origin);
                    break;
                case 'v':
                    $this->version = true;
                    break;
                case 'r':
                    $this->noBuiltinRules = true;
                    break;
                case 'R':
                    $this->noBuiltinVariables = true;
                    break;
                case 'e':
                    $this->environmentOverrides = true;
                    break;
                case 'w':
                    $this->switches->set('printDirectory', true, $origin);
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
                            "Option -$option requires " . ($option === 'f' ? 'an argument' : 'a directory'),
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
                            $this->execution->files->newFiles[] = $path;
                        } elseif ($option === 'o') {
                            $this->execution->files->oldFiles[] = $path;
                        } elseif ($option === 'f') {
                            if ($path === '-' && in_array('-', $this->input->makefiles, true)) {
                                throw new MakefileErrorException('Makefile from standard input specified twice');
                            }
                            $this->input->makefiles[] = $path;
                        } else {
                            $this->input->directories[] = $path;
                        }
                    }
                    return;
                default:
                    throw new UsageException("Option `$argument' is not supported");
            }
        }
    }

    public function __clone(): void
    {
        $this->input = clone $this->input;
        $this->switches = clone $this->switches;
        $this->execution = clone $this->execution;
    }
}
