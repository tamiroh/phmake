<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Files;

use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\Execution\BuildState;
use Tamiroh\Phmake\Makefile\Execution\ExecutionOptions;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;
use Tamiroh\Phmake\Makefile\Search\RuleSearch;

use function array_map;
use function implode;
use function in_array;
use function preg_replace;
use function str_starts_with;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * File names, timestamps, and intermediate lifetime for one build invocation.
 */
final class BuildFiles
{
    /** @var list<string> */
    public array $goals = [];

    /** @var array<string, array{string, string}> */
    private array $created = [];

    private readonly FilePolicy $policy;

    private readonly FileTable $table;

    /**
     * @throws MakefileErrorException
     */
    public function __construct(
        private readonly Makefile $makefile,
        private readonly RuleSearch $search,
        private readonly Filesystem $filesystem,
        private readonly Output $output,
        private readonly ExecutionOptions $options,
        private readonly BuildState $state,
    ) {
        $this->policy = new FilePolicy($makefile->targetsByName);
        $this->table = new FileTable();
        foreach ($makefile->targets as $target) {
            $this->table->enter($target->name);
            foreach ($target->rules as $rule) {
                foreach ($rule->prerequisites->sequence as $dependency) {
                    $this->table->enter($dependency);
                }
            }
        }
    }

    public function assumedOld(string $name): bool
    {
        foreach ($this->options->files->oldFiles as $file) {
            if (preg_replace('~^(?:\./)+~', '', $file) === preg_replace('~^(?:\./)+~', '', $this->path($name))) {
                return true;
            }
        }
        return false;
    }

    public function cleanup(): void
    {
        $removed = [];
        foreach ($this->table->names() as $name) {
            if (!isset($this->created[$name])) {
                continue;
            }
            [, $path] = $this->created[$name];
            if (
                $this->intermediate($name)
                && !$this->policy->keep($name)
                && $this->filesystem->exists($path)
                && $this->filesystem->remove($path)
            ) {
                $removed[] = $path;
            }
        }
        if ($removed !== []) {
            $this->output->writeLine('rm ' . implode(' ', $removed));
        }
    }

    public function intermediate(string $name): bool
    {
        return (
            !in_array($name, $this->goals, true)
            && !($this->makefile->targetsByName[$name]->isPhony ?? false)
            && $this->policy->intermediate($name, isset($this->search->state->intermediates[$name]))
        );
    }

    public function mapped(BuildRule $rule): BuildRule
    {
        return new BuildRule(
            new Prerequisites(
                array_map($this->path(...), $rule->prerequisites->normal),
                array_map($this->path(...), $rule->prerequisites->orderOnly),
            ),
            $rule->recipe,
            $rule->doubleColon,
            $rule->stem,
            $rule->group,
            $rule->firstPrerequisite === null ? null : $this->path($rule->firstPrerequisite),
            $rule->implicit,
        );
    }

    public function needsRebuild(BuildRule $rule, ?int $modifiedAt): bool
    {
        if ($modifiedAt === null) {
            return true;
        }
        foreach ([...$rule->prerequisites->normal, ...$rule->prerequisites->extra] as $dependency) {
            $time = $this->time($dependency);
            if ($time === null ? !$this->intermediate($dependency) : $time > $modifiedAt) {
                return true;
            }
        }
        return false;
    }

    public function path(string $name): string
    {
        return $this->search->state->paths[$name] ?? $name;
    }

    /**
     * @throws MakefileErrorException
     */
    public function prepare(string $name, VariableExpander $expander): void
    {
        $this->table->enter($name);
        $path = $this->path($name);
        if (
            $path !== $name
            && !str_starts_with($name, '-l')
            && !isset($this->makefile->targetsByName[$path])
            && !$this->makefile->paths->retain($path, $expander)
        ) {
            unset($this->search->state->paths[$name]);
            $this->search->state->discardedPaths[$name] = true;
        }
        $this->search->state->existed[$name] ??= $this->filesystem->exists($this->path($name));
        if (!$this->search->state->existed[$name]) {
            $this->created[$name] = [$name, $this->path($name)];
        }
    }

    public function time(string $name): ?int
    {
        $path = $this->path($name);
        if ($this->assumedOld($name)) {
            return PHP_INT_MIN;
        }
        if (isset($this->state->simulated[$path])) {
            return PHP_INT_MAX;
        }
        if (!$this->state->remaking || $this->state->restarts === 0) {
            foreach ($this->options->files->newFiles as $file) {
                if (preg_replace('~^(?:\./)+~', '', $file) === preg_replace('~^(?:\./)+~', '', $path)) {
                    return PHP_INT_MAX;
                }
            }
        }
        return $this->filesystem->lastModified($path);
    }
}
