<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\Diagnostics;
use Tamiroh\Phmake\Makefile\Evaluation\AutomaticVariables;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\Evaluation\VariableScope;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\RecipeOutput;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\Pattern;
use Tamiroh\Phmake\Makefile\Rule\Target;

use function array_map;
use function array_unique;
use function implode;
use function in_array;
use function preg_match;
use function preg_replace;

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
                $this->files->path($target->name),
                $this->files->mapped($rule),
                $modifiedAt,
                array_map($this->files->path(...), $changed),
                $this->filesystem,
                $this->files->time(...),
            ));
            $options = clone $options;
            $options->silent = $options->silent || $this->applies('.SILENT', $target->name);
            $options->ignoreErrors = $options->ignoreErrors || $this->applies('.IGNORE', $target->name);
            $oneShell = isset($this->makefile->targetsByName['.ONESHELL']);
            $commands = $rule->recipe->commands ?? [];
            if ($oneShell && $commands !== []) {
                $text = implode("\n", array_map(
                    static fn(Command $command): string => $command->expression,
                    $commands,
                ));
                if (preg_match('~(?:^|/)(?:ba|da|a|k|z|po)?sh(?:[ \t]|$)~', $expander->expand('$(SHELL)')) === 1) {
                    $text = preg_replace('/(?<=\n)[ \t@+-]+/', '', $text) ?? $text;
                }
                $commands = [new Command($text, $commands[0]->source)];
            }
            $expanded = [];
            foreach ($commands as $command) {
                $expanded[] = $command->expand($expander, $this->output);
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
            foreach ($expanded as $command) {
                $result = $command->run($shell, $this->output, $options, $oneShell, $target->name);
                if ($result->exitCode !== 0) {
                    $error = new CommandFailedException($target->name, $result->exitCode, $command->source);
                    if ($reportFailure || isset($this->makefile->targetsByName['.DELETE_ON_ERROR'])) {
                        Diagnostics::report($error, $this->output);
                    }
                    if (isset($this->makefile->targetsByName['.DELETE_ON_ERROR'])) {
                        foreach ([$target->name, ...$rule->group] as $name) {
                            if (
                                !$this->applies('.PRECIOUS', $name)
                                && !($this->makefile->targetsByName[$name]->isPhony ?? false)
                                && $this->filesystem->lastModified($name) !== ($timestamps[$name] ?? null)
                                && $this->filesystem->remove($name)
                            ) {
                                $this->output->writeWarning(
                                    '*** '
                                    . ($name === $target->name ? '' : "[{$target->name}] ")
                                    . "Deleting file '$name'",
                                );
                            }
                        }
                    }
                    throw $error;
                }
                $active = $active || $result->active;
                $simulated = $simulated || $result->simulated;
                $needsUpdate = $needsUpdate || $result->needsUpdate;
            }
            if (
                $options->touch
                && !$options->question
                && ($simulated || !$active && $commands !== [])
                && !$target->isPhony
            ) {
                if (!$options->silent) {
                    $this->output->write('touch ' . $target->name . "\n");
                }
                if (!$options->dryRun) {
                    $this->filesystem->touch($target->name);
                }
                $active = true;
                $simulated = $options->dryRun;
            }
            return new CommandResult($active, $simulated, needsUpdate: $needsUpdate);
        } catch (InterruptedException $error) {
            $this->output->writeWarning(
                '*** [' . (
                    $rule->recipe?->source === null ? '' : $rule->recipe->source . ': '
                ) . $target->name . '] ' . match ($error->signal) {
                    1 => 'Hangup',
                    2 => 'Interrupt',
                    3 => 'Quit',
                    15 => 'Terminated',
                    default => 'Interrupted',
                },
            );
            foreach ($timestamps ?? [] as $name => $timestamp) {
                if (
                    !$this->applies('.PRECIOUS', $name)
                    && !($this->makefile->targetsByName[$name]->isPhony ?? false)
                    && $this->filesystem->lastModified($name) !== $timestamp
                    && $this->filesystem->remove($name)
                ) {
                    $this->output->writeWarning("*** Deleting file '$name'");
                }
            }
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
        $target = $this->makefile->targetsByName[$special] ?? null;
        if ($target === null) {
            return false;
        }
        foreach ($target->rules as $rule) {
            if ($special === '.PRECIOUS') {
                foreach ($rule->prerequisites->normal as $pattern) {
                    if (new Pattern($pattern)->match($name) !== null) {
                        return true;
                    }
                }
            } elseif ($rule->prerequisites->normal === [] || in_array($name, $rule->prerequisites->normal, true)) {
                return true;
            }
        }
        return false;
    }
}
