<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Recipe;

use Tamiroh\Phmake\Engine\Execution\ExecutionOptions;
use Tamiroh\Phmake\Engine\Execution\Files\BuildFiles;
use Tamiroh\Phmake\Engine\Execution\InterruptedException;
use Tamiroh\Phmake\Engine\Expansion\CommandExpander;
use Tamiroh\Phmake\Engine\Expansion\ExpandedCommand;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\IO\RecipeOutput;
use Tamiroh\Phmake\Engine\IO\Shell;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Reporting\Diagnostics;
use Tamiroh\Phmake\Engine\Reporting\RecipeTrace;
use Tamiroh\Phmake\Engine\Rule\ArchiveMember;
use Tamiroh\Phmake\Engine\Rule\BuildRule;
use Tamiroh\Phmake\Engine\Rule\Command;
use Tamiroh\Phmake\Engine\Rule\Target;
use Tamiroh\Phmake\Engine\Variable\AutomaticVariables;
use Tamiroh\Phmake\Engine\Variable\Environment\ExportingShell;
use Tamiroh\Phmake\Engine\Variable\VariableScope;

use function array_any;
use function array_map;
use function array_unique;
use function implode;
use function in_array;
use function preg_match;
use function preg_replace;

/**
 * @internal
 */
final readonly class RecipeRunner
{
    public function __construct(
        private Makefile $makefile,
        private Shell $shell,
        private Filesystem $filesystem,
        private Output $output,
        private BuildFiles $files,
    ) {}

    /**
     * @param list<string> $changed
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(
        Target $target,
        BuildRule $rule,
        ?int $modifiedAt,
        array $changed,
        VariableScope $scope,
        ExecutionOptions $options,
        bool $reportFailure = true,
    ): CommandResult {
        if ($this->output instanceof RecipeOutput) {
            $this->output->beginTarget();
        }
        try {
            $expander = new VariableExpander(
                $scope->context,
                $this->output,
                scope: $scope,
            )->withVariables(AutomaticVariables::forRule(
                $this->files->states->path($target->name),
                $rule->mapped($this->files->states->path(...)),
                $modifiedAt,
                array_map($this->files->states->path(...), $changed),
                $this->filesystem,
                $this->files->states->time(...),
            ));
            $options = clone $options;
            $options->reporting->silent = $options->reporting->silent || $this->applies('.SILENT', $target->name);
            $options->ignoreErrors = $options->ignoreErrors || $this->applies('.IGNORE', $target->name);
            $oneShell = isset($this->makefile->targetsByName['.ONESHELL']);
            $commands = $rule->recipe->commands ?? [];
            if ($oneShell && $commands !== []) {
                $text = implode("\n", array_map(
                    static fn(Command $command): string => $command->expression,
                    $commands,
                ));
                $commands = [new Command($text, $commands[0]->source)];
            }
            $process =
                !$options->touch || array_any($commands, static fn(Command $command): bool => $command->isRecursive());
            $expanded = [];
            foreach ($process ? $commands : [] as $command) {
                $expandedCommand = CommandExpander::expand($command, $expander);
                if (
                    $oneShell
                    && preg_match('~(?:^|/)(?:ba|da|a|k|z|po)?sh(?:[ \t]|$)~', $expander->expand('$(SHELL)')) === 1
                ) {
                    $expandedCommand = new ExpandedCommand(
                        preg_replace('/(?<=\n)[ \t@+-]+/', '', $expandedCommand->expression)
                        ?? $expandedCommand->expression,
                        $expandedCommand->prefix,
                        $expandedCommand->recursive,
                        $expandedCommand->source,
                    );
                }
                $expanded[] = $expandedCommand;
            }
            if (
                $options->reporting->why
                && $commands !== []
                && (
                    !$options->touch
                    || array_any($commands, static fn(Command $command): bool => $command->isRecursive())
                )
            ) {
                RecipeTrace::write($target, $rule, $modifiedAt, $expander, $this->files, $this->output);
            }
            $shell = new ExportingShell($this->shell, $this->makefile->exports, $expander, $this->output);
            $timestamps = [];
            $missing = false;
            foreach (array_unique([$target->name, ...$rule->group]) as $name) {
                // GNU make stops checking peer timestamps after a missing group member.
                $timestamps[$name] = $missing ? null : $this->filesystem->lastModified($name);
                $missing = $missing || $timestamps[$name] === null;
            }
            $active = false;
            $simulated = false;
            $needsUpdate = false;
            $started = false;
            $nonrecursive = !$process || $commands === [];
            foreach ($expanded as $command) {
                $result = new CommandRunner($command)->run($shell, $this->output, $options, $oneShell, $target->name);
                if ($result->exitCode !== 0) {
                    $error = new CommandFailedException($target->name, $result->exitCode, $command->source);
                    if ($reportFailure || isset($this->makefile->targetsByName['.DELETE_ON_ERROR'])) {
                        Diagnostics::report($error, $this->output);
                    }
                    if (isset($this->makefile->targetsByName['.DELETE_ON_ERROR'])) {
                        foreach ([$target->name, ...$rule->group] as $name) {
                            $this->deleteFailedTarget(
                                $name,
                                $timestamps[$name] ?? null,
                                $name === $target->name ? null : $target->name,
                            );
                        }
                    }
                    throw $error;
                }
                $active = $active || $result->active;
                $simulated = $simulated || $result->simulated;
                $needsUpdate = $result->needsUpdate;
                $started = $started || $result->started;
                $nonrecursive = $nonrecursive || !$result->recursive;
                if ($needsUpdate) {
                    break;
                }
            }
            if (
                $options->touch
                && !$options->question
                && $rule->recipe !== null
                && $nonrecursive
                && !$target->isPhony
            ) {
                if (!$options->reporting->silent) {
                    $this->output->write('touch ' . $target->name . "\n");
                }
                if (!$options->dryRun) {
                    try {
                        $this->filesystem->touch($target->name);
                    } catch (MakefileErrorException $error) {
                        if (ArchiveMember::parse($target->name) !== null) {
                            $this->output->writeWarning($error->getMessage());
                            $error->reported = true;
                        }
                        throw $error;
                    }
                }
                $active = true;
                $simulated = $options->dryRun;
            }
            return new CommandResult($active, $simulated, needsUpdate: $needsUpdate, started: $started);
        } catch (InterruptedException $error) {
            foreach ($timestamps ?? [] as $name => $timestamp) {
                $this->deleteFailedTarget($name, $timestamp);
            }
            $this->output->writeWarning(
                '*** ['
                . ($rule->recipe?->source === null ? '' : $rule->recipe->source . ': ')
                . $target->name
                . '] '
                . $error->signal,
            );
            throw $error;
        } catch (MakefileErrorException $error) {
            Diagnostics::report($error, $this->output);
            throw $error;
        } finally {
            if ($this->output instanceof RecipeOutput) {
                $this->output->endTarget();
            }
        }
    }

    private function applies(string $special, string $name): bool
    {
        // GNU applies .IGNORE and .SILENT from the root double-colon entry.
        $rule = $this->makefile->targetsByName[$special]->rules[0] ?? null;
        if ($rule === null) {
            return false;
        }
        if ($rule->prerequisites->sequence === []) {
            return true;
        }
        foreach ($this->files->states->record($name)->names as $alias => $_) {
            if (in_array($alias, $rule->prerequisites->sequence, true)) {
                return true;
            }
        }
        return false;
    }

    private function deleteFailedTarget(string $name, ?int $timestamp, ?string $onBehalfOf = null): void
    {
        if (
            $this->files->precious($name)
            || ($this->makefile->targetsByName[$name]->isPhony ?? false)
            || $this->filesystem->lastModified($name) === $timestamp
        ) {
            return;
        }
        $prefix = '*** ' . ($onBehalfOf === null ? '' : "[{$onBehalfOf}] ");
        if (ArchiveMember::parse($name) !== null) {
            $this->output->writeWarning($prefix . "Archive member '{$name}' may be bogus; not deleted");
        } elseif ($this->filesystem->remove($name)) {
            $this->output->writeWarning($prefix . "Deleting file '{$name}'");
        }
    }
}
