<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Diagnostics;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Execution\Build;
use Tamiroh\Phmake\Makefile\Execution\CommandFailedException;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\MakefileSources;

use function array_values;
use function file_get_contents;
use function getcwd;
use function implode;
use function in_array;
use function is_file;
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
     */
    public function load(): Build
    {
        $stdin = in_array('-', $this->commandLine->input->makefiles, true) ? file_get_contents('php://stdin') : null;
        $restarts = 0;
        while (true) {
            Signals::check();
            $configuration = clone $this->commandLine;
            $sources = new MakefileSources(
                new SourceFiles(),
                new Filesystem(),
                $configuration->input->makefiles === [] ? $this->defaultMakefiles() : $configuration->input->makefiles,
                $configuration,
                $stdin === false ? '' : $stdin,
                $configuration->input->makefiles === [],
                $configuration->input->evaluations,
            );
            $this->output->beginTarget();
            try {
                $makefile = new MakefileParser(
                    $sources,
                    $this->variables($configuration, $restarts),
                    $configuration->variables,
                    $configuration->noBuiltinRules ? [] : Builtins::rules(),
                    $this->output,
                    $configuration,
                    new Shell(output: $this->output),
                    new Filesystem(),
                    $configuration->execution->reporting,
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
                new Shell(jobserver: $slots, output: $this->output),
                new Filesystem(),
                $this->output,
                $configuration->execution,
                $restarts,
                $slots,
            );
            if ($makefile->context !== null) {
                $makefile->context->variables['MFLAGS'] = new Variable(
                    'MFLAGS',
                    $configuration->makeflags($restarts, legacy: true),
                    true,
                    'environment',
                );
                $makefile->context->variables['MAKEFLAGS'] = new Variable(
                    'MAKEFLAGS',
                    $configuration->makeflags($restarts),
                    false,
                );
            }
            try {
                if ($this->remake($sources, $build, $configuration->execution->keepGoing)) {
                    $build->cleanup();
                    $restarts++;
                    continue;
                }
            } catch (MakefileErrorException|CommandFailedException $error) {
                $build->cleanup();
                throw $error;
            } finally {
                if ($makefile->context !== null) {
                    $makefile->context->variables['MFLAGS'] = new Variable(
                        'MFLAGS',
                        $configuration->makeflags(legacy: true),
                        true,
                        'environment',
                    );
                    $makefile->context->variables['MAKEFLAGS'] = new Variable(
                        'MAKEFLAGS',
                        $configuration->makeflags(),
                        false,
                    );
                }
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
            if ($file->text !== null && in_array($file->path, $sources->main, true)) {
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
                if ($file->text === null && $file->source !== null) {
                    $this->output->writeWarning(
                        $file->path . ': ' . ($file->error ?? 'No such file or directory'),
                        $file->source,
                    );
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
     * @return list<Variable>
     */
    private function variables(CommandLine $commandLine, int $restarts): array
    {
        $defaults = $this->defaults;
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
                    'environment override',
                );
            }
        }
        return [
            ...array_values($defaults),
            new Variable('MAKEFLAGS', $commandLine->makeflags(), false),
            new Variable('.DEFAULT_GOAL', '', false),
            new Variable('MFLAGS', $commandLine->makeflags(legacy: true), true, 'environment'),
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
