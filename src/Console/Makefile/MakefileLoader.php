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
use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\Execution\UnremadeMakefileException;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Reporting\DebugTrace;
use Tamiroh\Phmake\Makefile\Reporting\Diagnostics;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;
use Tamiroh\Phmake\Parser\Source\MakefileSources;
use Tamiroh\Phmake\Parser\Source\ReadFile;

use function array_values;
use function function_exists;
use function getcwd;
use function implode;
use function in_array;
use function is_file;
use function ltrim;
use function scandir;

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
            $sources = new MakefileSources(
                new SourceFiles(),
                $filesystem,
                $configuration->input->makefiles === [] ? $this->defaultMakefiles() : $configuration->input->makefiles,
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
                    $this->variables($configuration, $restarts),
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
            );
            if ($makefile->context !== null) {
                MakeFlags::define($configuration, $makefile->context->variables, $makefile->context->posix, $restarts);
            }
            try {
                $remade = $this->remake($sources, $build, $configuration->execution->keepGoing);
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
            if ($this->commandLine->targets === [] && $makefile->defaultGoal === null && !$this->hasMain($sources)) {
                throw new MakefileErrorException('No targets specified and no makefile found');
            }
            return $build;
        }
    }

    /**
     * @return list<string>
     */
    private function defaultMakefiles(): array
    {
        $entries = scandir('.');
        if ($entries !== false) {
            foreach (['GNUmakefile', 'makefile', 'Makefile'] as $path) {
                if (in_array($path, $entries, true) && is_file($path)) {
                    return [$path];
                }
            }
        }
        return ['GNUmakefile', 'makefile', 'Makefile'];
    }

    private function hasMain(MakefileSources $sources): bool
    {
        foreach ($sources->read as $file) {
            if (
                $file->text !== null
                && (
                    in_array($file->path, $sources->main, true)
                    || !$file->rebuild && in_array('-', $sources->main, true)
                )
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    private function remake(MakefileSources $sources, Build $build, bool $keepGoing): bool
    {
        $names = [];
        $inputs = [];
        $unreadable = [];
        foreach ($sources->read as $file) {
            if ($file->rebuild) {
                $inputs[$file->path] = $file;
            }
        }
        foreach ($inputs as $file) {
            $names[] = $file->path;
            if ($file->text === null) {
                $unreadable[] = $file->path;
            }
        }
        $errors = $build->remake($names, $unreadable);
        foreach ($inputs as $file) {
            if (
                $file->rebuild
                && ($contents = $sources->files->read($file->path))->text !== null
                && ($contents->text !== $file->text || $contents->modifiedAt !== $file->modifiedAt)
            ) {
                return true;
            }
        }
        foreach ($sources->read as $file) {
            if (
                !$file->optional
                && $file->text === null
                && $file->modifiedAt !== null
                && $sources->files->read($file->path)->text === null
                && !isset($errors[$file->path])
            ) {
                $errors[$file->path] = new MakefileErrorException("No rule to make target '{$file->path}'");
            }
            if (!$file->optional && isset($errors[$file->path])) {
                if (
                    $errors[$file->path] instanceof CommandFailedException
                    && $errors[$file->path]->target !== $file->path
                    && ($inputs[$errors[$file->path]->target]->optional ?? false)
                ) {
                    $this->output->writeWarning("Failed to remake makefile '$file->path'.", $file->source);
                    $errors[$file->path]->reported = true;
                    throw $errors[$file->path];
                }
                if ($file->text === null && $file->source !== null) {
                    $this->output->writeWarning(
                        $file->path . ': ' . ($file->error ?? 'No such file or directory'),
                        $file->source,
                    );
                }
                if ($errors[$file->path] instanceof UnremadeMakefileException) {
                    $errors[$file->path]->reported = true;
                    throw $errors[$file->path];
                }
                if ($keepGoing) {
                    Diagnostics::report($errors[$file->path], $this->output, false);
                    $this->output->writeWarning("Failed to remake makefile '$file->path'.", $file->source);
                }
                throw $errors[$file->path];
            }
        }
        return false;
    }

    /**
     * @throws MakefileErrorException
     *
     * @return list<Variable>
     */
    private function variables(CommandLine $commandLine, int $restarts): array
    {
        $defaults = CommandVariables::definitions($commandLine->variables, [
            ...$this->defaults,
            ...$commandLine->variables,
        ]);
        foreach ($defaults as $name => $variable) {
            if (
                $commandLine->noBuiltinVariables
                && $variable->origin === 'default'
                && !in_array($name, Builtins::INTERNAL_VARIABLES, true)
            ) {
                unset($defaults[$name]);
            } elseif ($commandLine->environmentOverrides && $variable->origin === 'environment') {
                $defaults[$name] = new Variable(
                    $name,
                    $variable->expression,
                    $variable->recursive,
                    'environment',
                    environmentOverrides: true,
                );
            }
        }
        if ($commandLine->noBuiltinRules && ($defaults['SUFFIXES']->origin ?? '') === 'default') {
            $defaults['SUFFIXES'] = new Variable('SUFFIXES', '', false, 'default');
        }
        MakeFlags::define($commandLine, $defaults);
        return [
            ...array_values($defaults),
            new Variable('.DEFAULT_GOAL', '', false),
            ...(
                isset($defaults['GNUMAKEFLAGS'])
                    ? [new Variable('GNUMAKEFLAGS', '', true, $defaults['GNUMAKEFLAGS']->origin)]
                    : []
            ),
            new Variable('MAKELEVEL', (string) $this->level, false, 'environment'),
            new Variable('CURDIR', (string) getcwd(), false),
            ...(
                $commandLine->targets === []
                    ? []
                    : [new Variable('MAKECMDGOALS', implode(' ', $commandLine->targets), false, 'default')]
            ),
            ...($restarts === 0 ? [] : [new Variable('MAKE_RESTARTS', (string) $restarts, false, export: false)]),
        ];
    }
}
