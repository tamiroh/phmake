<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Makefile;

use Tamiroh\Phmake\Console\Filesystem\DeletionOrder;
use Tamiroh\Phmake\Console\Filesystem\Filesystem;
use Tamiroh\Phmake\Console\Input\CommandLine;
use Tamiroh\Phmake\Console\Makefile\Internal\StdinMakefile;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Console\Process\FiberUpdates;
use Tamiroh\Phmake\Console\Process\Jobserver;
use Tamiroh\Phmake\Console\Process\ModuleGuile;
use Tamiroh\Phmake\Console\Process\ModuleHost;
use Tamiroh\Phmake\Console\Process\ModuleObjects;
use Tamiroh\Phmake\Console\Process\ProcessRestart;
use Tamiroh\Phmake\Console\Process\Shell;
use Tamiroh\Phmake\Console\Process\Signals;
use Tamiroh\Phmake\Engine\Builtins;
use Tamiroh\Phmake\Engine\Engine;
use Tamiroh\Phmake\Engine\Evaluation\Evaluator;
use Tamiroh\Phmake\Engine\Evaluation\MakefileSources;
use Tamiroh\Phmake\Engine\Execution\Build;
use Tamiroh\Phmake\Engine\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Engine\Expansion\LoadedObject\LoadedObjects;
use Tamiroh\Phmake\Engine\Invocation\CommandVariables;
use Tamiroh\Phmake\Engine\Invocation\MakeFlags;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\ReadFile;
use Tamiroh\Phmake\Engine\Reporting\DebugTrace;
use Tamiroh\Phmake\Engine\Reporting\Diagnostics;
use Tamiroh\Phmake\Engine\RunResult;
use Tamiroh\Phmake\Engine\Variable\Variable;

use function array_values;
use function function_exists;
use function in_array;
use function ltrim;

/**
 * Each restart reconstructs evaluation and build state from the original invocation.
 */
final readonly class MakeCommand
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
     */
    public function run(): int
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
                [$makefile, $context] = new Evaluator(
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
                $makefile,
                $context,
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
            $engine = new Engine(
                $build,
                $context,
                $filesystem,
                $this->output,
                $configuration->options,
                $configuration->execution,
            );
            $result = $engine->run($sources->read, $this->commandLine->targets, $restarts);
            if ($result === RunResult::NeedsReevaluation) {
                $restarts++;
                if ($stdin !== null && function_exists('pcntl_exec')) {
                    unset($engine, $build, $slots, $makefile, $context);
                    ProcessRestart::execute($configuration, $stdin->path, $restarts, $this->output);
                }
                continue;
            }
            return match ($result) {
                RunResult::Succeeded => 0,
                RunResult::OutOfDate => 1,
                RunResult::Failed => 2,
            };
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
