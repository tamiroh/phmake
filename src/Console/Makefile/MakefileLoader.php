<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Makefile;

use Tamiroh\Phmake\Console\Filesystem\Filesystem;
use Tamiroh\Phmake\Console\Input\CommandLine;
use Tamiroh\Phmake\Console\Input\CommandVariables;
use Tamiroh\Phmake\Console\Input\MakeFlags;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Console\Process\Jobserver;
use Tamiroh\Phmake\Console\Process\ModuleHost;
use Tamiroh\Phmake\Console\Process\ProcessRestart;
use Tamiroh\Phmake\Console\Process\Shell;
use Tamiroh\Phmake\Console\Process\Signals;
use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Evaluation\Module\Modules;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\Execution\Build;
use Tamiroh\Phmake\Makefile\Execution\MakefileRemake;
use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\ReadFile;
use Tamiroh\Phmake\Makefile\Reporting\DebugTrace;
use Tamiroh\Phmake\Makefile\Reporting\Diagnostics;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;
use Tamiroh\Phmake\Parser\Source\MakefileSources;

use function array_map;
use function array_values;
use function function_exists;
use function in_array;
use function ltrim;

/**
 * Each restart reconstructs parser and build state from the original invocation.
 */
final readonly class MakefileLoader
{
    /**
     * @param array<string, Variable> $defaults
     */
    public function __construct(
        private CommandLine $commandLine,
        private Output $output,
        private array $defaults,
        private int $level,
    ) {}

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     * @throws ParseException
     */
    public function load(): Build
    {
        $stdin = in_array('-', $this->commandLine->input->makefiles, true)
            ? new StdinMakefile($this->commandLine->input->temporaryStdin)
            : null;
        $restarts = (int) ltrim((string) $this->commandLine->input->restarts, '-');
        while (true) {
            Signals::check();
            $configuration = clone $this->commandLine;
            $filesystem = new Filesystem();
            $filesystem->options = $configuration->execution->files;
            $files = new SourceFiles();
            $sources = new MakefileSources(
                $files,
                $filesystem,
                $configuration->input->makefiles === []
                    ? Builtins::makefiles($files)
                    : $configuration->input->makefiles,
                $configuration,
                $stdin === null ? null : new ReadFile($stdin->path, $stdin->text, null, rebuild: false),
                $configuration->input->makefiles === [],
                $configuration->input->evaluations,
                $restarts > 0,
            );
            DebugTrace::write($configuration->execution->reporting, $this->output, 'b', 'Reading makefiles...');
            $this->output->beginTarget();
            try {
                $makefile = new MakefileParser(
                    $sources,
                    $this->variables($configuration, $filesystem->workingDirectory(), $restarts),
                    $configuration->variables,
                    $configuration->noBuiltinRules ? [] : Builtins::rules(),
                    $this->output,
                    $configuration,
                    new Shell(output: $this->output, reporting: $configuration->execution->reporting),
                    $filesystem,
                    $configuration->execution->reporting,
                    new Modules(
                        ($moduleHost = ModuleHost::executable()) === null
                            ? null
                            : fn(): ModuleHost => new ModuleHost($moduleHost, $this->output),
                    ),
                )->parse();
            } catch (MakefileErrorException $error) {
                Diagnostics::report($error, $this->output);
                throw $error;
            } finally {
                $this->output->endTarget();
            }
            $this->output->buffer->options = $configuration->execution->parallel;
            $this->output->buffer->prepare();
            if ($restarts > 0) {
                $configuration->execution->parallel->reset = false;
            }
            $slots = new Jobserver($configuration->execution->parallel, $this->output);
            $build = new Build(
                $makefile,
                new Shell(jobserver: $slots, output: $this->output, reporting: $configuration->execution->reporting),
                $filesystem,
                $this->output,
                $configuration->execution,
                $restarts,
                $slots,
                $sources->foundMain(),
            );
            if ($makefile->context !== null) {
                MakeFlags::define($configuration, $makefile->context->variables, $makefile->context->posix, $restarts);
            }
            try {
                $remade = new MakefileRemake(
                    $build,
                    $sources->files,
                    $this->output,
                    $configuration->execution->keepGoing,
                )->run($sources->read);
                if (!$remade && $makefile->context !== null) {
                    $makefile->context->modules->reload(new VariableExpander($makefile->context, $this->output));
                }
            } catch (MakefileErrorException|CommandFailedException $error) {
                $build->cleanup();
                throw $error;
            } finally {
                if ($makefile->context !== null) {
                    MakeFlags::define($configuration, $makefile->context->variables, $makefile->context->posix);
                }
            }
            if ($remade) {
                $build->cleanup();
                $restarts++;
                if ($stdin !== null && function_exists('pcntl_exec')) {
                    unset($build, $slots, $makefile);
                    ProcessRestart::execute($configuration, $stdin->path, $restarts, $this->output);
                }
                continue;
            }
            return $build;
        }
    }

    /**
     * @throws MakefileErrorException
     *
     * @return list<Variable>
     */
    private function variables(CommandLine $commandLine, string $directory, int $restarts): array
    {
        $defaults = CommandVariables::definitions($commandLine->variables, [
            ...$this->defaults,
            ...$commandLine->variables,
        ]);
        if ($commandLine->noBuiltinVariables) {
            $defaults = Builtins::withoutVariables($defaults);
        }
        if ($commandLine->environmentOverrides) {
            $defaults = array_map(
                static fn(Variable $variable): Variable => $variable->withEnvironmentOverrides(),
                $defaults,
            );
        }
        if ($commandLine->noBuiltinRules && ($defaults['SUFFIXES']->origin ?? '') === 'default') {
            $defaults['SUFFIXES'] = new Variable('SUFFIXES', '', false, 'default');
        }
        MakeFlags::define($commandLine, $defaults);
        return [
            ...array_values($defaults),
            ...Builtins::invocationVariables($defaults, $commandLine->targets, $this->level, $directory, $restarts),
        ];
    }
}
