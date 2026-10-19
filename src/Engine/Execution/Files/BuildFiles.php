<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Files;

use Tamiroh\Phmake\Engine\Execution\BuildState;
use Tamiroh\Phmake\Engine\Execution\ExecutionOptions;
use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\IntermediateDeletionOrder;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\BuildRule;
use Tamiroh\Phmake\Engine\Search\RuleSearch;

use function implode;
use function in_array;
use function str_starts_with;

/**
 * File preparation and intermediate lifetime for one build invocation.
 *
 * @internal
 */
final class BuildFiles
{
    /** @var list<string> */
    public array $goals = [];

    /** @var array<string, array{string, string}> */
    private array $created = [];

    public readonly FileStates $states;

    private readonly IntermediateFiles $intermediates;

    /**
     * @throws MakefileErrorException
     */
    public function __construct(
        private readonly Makefile $makefile,
        private readonly RuleSearch $search,
        private readonly Filesystem $filesystem,
        private readonly Output $output,
        private readonly ExecutionOptions $options,
        BuildState $state,
        private readonly IntermediateDeletionOrder $deletionOrder,
    ) {
        $this->states = new FileStates($makefile, $search, $filesystem, $options, $state, $output);
        $this->intermediates = new IntermediateFiles($makefile->special);
        foreach ($makefile->targets as $target) {
            $this->deletionOrder->enter($target->name);
            foreach ($target->rules as $rule) {
                foreach ($rule->prerequisites->sequence as $dependency) {
                    $this->deletionOrder->enter($dependency);
                }
            }
        }
    }

    public function cleanup(): void
    {
        if (
            $this->options->question
            || $this->options->touch
            || $this->makefile->special->hasEmptyRule('.NOTINTERMEDIATE')
        ) {
            return;
        }
        $removable = [];
        foreach ($this->created as [$name, $path]) {
            if ($this->intermediate($name) && !$this->intermediates->preserve($name) && !$this->precious($name)) {
                $removable[$name] = $path;
            }
        }
        // Only deletions need this order, so most builds never compute it.
        if ($removable === []) {
            return;
        }
        $removed = [];
        foreach ($this->deletionOrder->names() as $name) {
            $path = $removable[$name] ?? null;
            if (
                $path !== null
                && ($this->options->dryRun || $this->filesystem->exists($path) && $this->filesystem->remove($path))
            ) {
                $removed[] = $path;
            }
        }
        if ($removed !== [] && !$this->options->reporting->silent) {
            $this->output->writeLine('rm ' . implode(' ', $removed));
        }
    }

    public function intermediate(string $name): bool
    {
        return (
            !in_array($name, $this->goals, true)
            && !($this->makefile->targetsByName[$name]->isPhony ?? false)
            && $this->intermediates->intermediate(
                $name,
                isset($this->search->state->intermediates[$name]),
                isset($this->search->state->notIntermediate[$name]),
            )
        );
    }

    public function needsRebuild(BuildRule $rule, ?int $modifiedAt): bool
    {
        if ($modifiedAt === null) {
            return true;
        }
        foreach ([...$rule->prerequisites->normal, ...$rule->prerequisites->extra] as $dependency) {
            $time = $this->states->time($dependency);
            if ($time === null ? !$this->intermediate($dependency) : $time > $modifiedAt) {
                return true;
            }
        }
        return false;
    }

    public function precious(string $name): bool
    {
        if ($this->states->hasSpecial('.PRECIOUS', $name)) {
            return true;
        }
        foreach ($this->states->record($name)->names as $alias => $_) {
            if (isset($this->search->state->precious[$alias])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @throws MakefileErrorException
     */
    public function prepare(string $name): void
    {
        $this->deletionOrder->enter($name);
        $path = $this->states->path($name);
        if (
            $path !== $name
            && !str_starts_with($name, '-l')
            && !isset($this->makefile->targetsByName[$path])
            && !$this->makefile->searchPaths->retain($path)
        ) {
            unset($this->search->state->paths[$name]);
            $this->search->state->discardedPaths[$name] = true;
        }
        $this->search->state->existed[$name] ??= $this->filesystem->exists($this->states->path($name));
        if (!$this->search->state->existed[$name]) {
            $this->created[$name] = [$name, $this->states->path($name)];
        }
    }
}
