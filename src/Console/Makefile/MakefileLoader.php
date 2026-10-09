<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Makefile;

use Tamiroh\Phmake\Console\Filesystem\DeletionOrder;
use Tamiroh\Phmake\Console\Filesystem\Filesystem;
use Tamiroh\Phmake\Console\Input\CommandLine;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Console\Process\FiberUpdates;
use Tamiroh\Phmake\Console\Process\Jobserver;
use Tamiroh\Phmake\Console\Process\ModuleGuile;
use Tamiroh\Phmake\Console\Process\ModuleHost;
use Tamiroh\Phmake\Console\Process\ModuleObjects;
use Tamiroh\Phmake\Console\Process\ProcessRestart;
use Tamiroh\Phmake\Console\Process\Shell;
use Tamiroh\Phmake\Console\Process\Signals;
use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Execution\Build;
use Tamiroh\Phmake\Makefile\Execution\MakefileRemake;
use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\Expansion\LoadedObject\LoadedObjects;
use Tamiroh\Phmake\Makefile\Expansion\VariableExpander;
use Tamiroh\Phmake\Makefile\Invocation\CommandVariables;
use Tamiroh\Phmake\Makefile\Invocation\MakeFlags;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\ReadFile;
use Tamiroh\Phmake\Makefile\Reporting\DebugTrace;
use Tamiroh\Phmake\Makefile\Reporting\Diagnostics;
use Tamiroh\Phmake\Makefile\Variable\Variable;
use Tamiroh\Phmake\Parser\Evaluator;
use Tamiroh\Phmake\Parser\ParseException;
use Tamiroh\Phmake\Parser\Source\MakefileSources;

use function array_values;
use function function_exists;
use function in_array;
use function ltrim;

/**
 * Each restart reconstructs evaluation and build state from the original invocation.
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
            $sources = new MakefileSources(
                $filesystem,
                $configuration->input->makefiles === []
                    ? Builtins::makefiles($filesystem)
                    : $configuration->input->makefiles,
                $configuration,
                $stdin === null ? null : new ReadFile($stdin->path, $stdin->text, null, rebuild: false),
                $configuration->input->makefiles === [],
                $configuration->options->evaluations,
                $restarts > 0,
            );
            DebugTrace::write($configuration->execution->reporting, $this->output, 'b', 'Reading makefiles...');
            $this->output->beginTarget();
            try {
                $parsed = new Evaluator(
                    $sources,
                    $this->variables($configuration, $filesystem->workingDirectory(), $restarts),
                    $configuration->variables,
                    $configuration->noBuiltinRules ? [] : Builtins::rules(),
                    $this->output,
                    $configuration,
                    new Shell(output: $this->output, reporting: $configuration->execution->reporting),
                    $filesystem,
                    $configuration->execution->reporting,
                    new LoadedObjects(
                        ($moduleHost = ModuleHost::executable()) === null
                            ? null
                            : new ModuleObjects($moduleHost, $this->output),
                    ),
                    $moduleHost === null ? null : new ModuleGuile($moduleHost, $this->output),
                )->evaluate();
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
                $parsed->makefile,
                $parsed->context,
                new Shell(jobserver: $slots, output: $this->output, reporting: $configuration->execution->reporting),
                $filesystem,
                $this->output,
                new FiberUpdates(),
                new DeletionOrder(),
                $configuration->execution,
                $restarts,
                $slots,
                $sources->foundMain(),
            );
            MakeFlags::define(
                $configuration->options,
                $configuration->execution,
                $parsed->context->variables,
                $parsed->context->reading->posix,
                $restarts,
            );
            try {
                $remade = new MakefileRemake(
                    $build,
                    $sources->filesystem,
                    $this->output,
                    $configuration->execution->keepGoing,
                )->run($sources->read);
                if (!$remade) {
                    $parsed->context->loadedObjects->reload(new VariableExpander($parsed->context, $this->output));
                }
            } catch (MakefileErrorException|CommandFailedException $error) {
                $build->cleanup();
                throw $error;
            } finally {
                MakeFlags::define(
                    $configuration->options,
                    $configuration->execution,
                    $parsed->context->variables,
                    $parsed->context->reading->posix,
                );
            }
            if ($remade) {
                $build->cleanup();
                $restarts++;
                if ($stdin !== null && function_exists('pcntl_exec')) {
                    unset($build, $slots, $parsed);
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
        $defaults = $commandLine->options->beforeReading($defaults);
        MakeFlags::define($commandLine->options, $commandLine->execution, $defaults);
        return [
            ...array_values($defaults),
            ...Builtins::invocationVariables($defaults, $commandLine->targets, $this->level, $directory, $restarts),
        ];
    }
}
