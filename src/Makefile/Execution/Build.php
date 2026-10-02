<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\Execution\Files\BuildFiles;
use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\Execution\Recipe\RecipeRunner;
use Tamiroh\Phmake\Makefile\Execution\Scheduling\DependencyOrder;
use Tamiroh\Phmake\Makefile\Execution\Scheduling\Jobs;
use Tamiroh\Phmake\Makefile\Expansion\EvaluationContext;
use Tamiroh\Phmake\Makefile\Expansion\ExtraPrerequisites;
use Tamiroh\Phmake\Makefile\Expansion\SecondaryExpansion;
use Tamiroh\Phmake\Makefile\Expansion\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\IntermediateDeletionOrder;
use Tamiroh\Phmake\Makefile\IO\JobSlots;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\IO\TargetUpdates;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Reporting\DebugTrace;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\FileName;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;
use Tamiroh\Phmake\Makefile\Rule\Target;
use Tamiroh\Phmake\Makefile\Search\RuleSearch;
use Tamiroh\Phmake\Makefile\Variable\VariableScope;

use function array_key_exists;
use function array_unique;
use function count;
use function implode;
use function in_array;
use function spl_object_id;

/**
 * Execution state belongs to one invocation, independently of the parsed rules.
 */
final class Build
{
    private readonly BuildState $state;

    private readonly RecipeRunner $runner;

    private readonly RuleSearch $search;

    private readonly PrerequisiteChain $chain;

    private readonly Jobs $jobs;

    private readonly BuildFiles $files;

    /**
     * @throws MakefileErrorException
     */
    public function __construct(
        private readonly Makefile $makefile,
        private readonly EvaluationContext $context,
        Shell $shell,
        private readonly Filesystem $filesystem,
        private readonly Output $output,
        TargetUpdates $updates,
        IntermediateDeletionOrder $deletionOrder,
        private readonly ExecutionOptions $options = new ExecutionOptions(),
        int $restarts = 0,
        ?JobSlots $slots = null,
        private readonly bool $makefileFound = true,
    ) {
        $this->state = new BuildState($restarts);
        $this->search = new RuleSearch($makefile, $filesystem, $output);
        $this->files = new BuildFiles(
            $makefile,
            $this->search,
            $filesystem,
            $output,
            $options,
            $this->state,
            $deletionOrder,
        );
        $this->runner = new RecipeRunner($makefile, $shell, $filesystem, $output, $this->files);
        $this->chain = new PrerequisiteChain(new VariableScope($context));
        $this->jobs = new Jobs($updates, $slots, $output);
    }

    public function cleanup(): void
    {
        $this->files->cleanup();
    }

    /**
     * Update input makefiles quietly, retaining successful results for ordinary goals.
     *
     * @param list<string> $names
     * @param list<string> $unreadable
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     *
     * @return array<string, MakefileErrorException|CommandFailedException>
     */
    public function remake(array $names, array $unreadable = []): array
    {
        $this->files->goals = $names;
        DebugTrace::write($this->options->reporting, $this->output, 'b', 'Updating makefiles....');
        $this->state->remaking = true;
        $this->options->reporting->remaking = true;
        $errors = [];
        $work = [];
        foreach (array_unique($names) as $name) {
            $this->search->state->mentioned[$name] = true;
            unset($this->search->state->intermediates[$name]);
            $target = $this->makefile->targetsByName[$name] ?? null;
            if ($target?->isPhony === true) {
                if (!$this->filesystem->exists($name)) {
                    $errors[$name] = new UnremadeMakefileException("No rule to make target '{$name}'");
                }
                continue;
            }
            foreach ($target->rules ?? [] as $rule) {
                if ($rule->doubleColon && $rule->prerequisites->sequence === [] && $rule->recipe !== null) {
                    if (!$this->filesystem->exists($name)) {
                        $errors[$name] = new UnremadeMakefileException("No rule to make target '{$name}'");
                    }
                    continue 2;
                }
            }
            $work[$name] =
                /**
                 * @throws MakefileErrorException
                 * @throws CommandFailedException
                 */
                function () use ($name, $unreadable): UpdateResult {
                    $executed = false;
                    return $this->update(
                        $name,
                        $executed,
                        clone $this->chain,
                        force: in_array($name, $unreadable, true),
                    );
                };
        }
        try {
            if ($this->parallel()) {
                $results = $this->jobs->updateAll($work);
            } else {
                $results = [];
                foreach ($work as $name => $callback) {
                    $results[$name] = $callback();
                }
            }
            foreach ($results as $name => $result) {
                if ($result->failure !== null) {
                    $errors[$name] = $result->failure;
                }
            }
        } finally {
            $this->jobs->waitForUnfinishedJobs();
        }
        foreach ($this->state->results as $name => $result) {
            if ($result->failure !== null) {
                unset($this->state->results[$name]);
            }
        }
        $this->state->remaking = false;
        $this->options->reporting->remaking = false;
        $this->state->failed = false;
        return $errors;
    }

    /**
     * @param list<string> $names
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(array $names): int
    {
        try {
            if ($names === []) {
                $names = [
                    $this->makefile->defaultGoal ?? throw new MakefileErrorException(
                        $this->makefileFound ? 'No targets' : 'No targets specified and no makefile found',
                    ),
                ];
            }
            DebugTrace::write($this->options->reporting, $this->output, 'b', 'Updating goal targets....');
            $names = array_map(FileName::normalize(...), $names);
            $this->files->goals = $names;
            $names = DependencyOrder::arrange($names, $this->options->parallel, $this->makefile);
            $work = [];
            foreach ($names as $name) {
                $work[$name] =
                    /**
                     * @throws MakefileErrorException
                     * @throws CommandFailedException
                     */
                    function () use ($name): UpdateResult {
                        $executed = false;
                        $result = $this->update($name, $executed, clone $this->chain);
                        if ($result->failure !== null) {
                            $this->state->failed = true;
                            if ($result->blocked) {
                                $this->output->writeWarning("Target '{$name}' not remade because of errors.");
                            }
                        } elseif (!$executed && !$this->options->question && !$this->options->reporting->silent) {
                            $target = $this->search->resolve($name, $this->chain->scope);
                            $this->output->writeInfo(
                                $target === null || $target->isPhony || ($target->rules[0]->recipe ?? null) === null
                                    ? "Nothing to be done for '{$name}'."
                                    : "'{$name}' is up to date.",
                            );
                        }
                        return $result;
                    };
                if (!$this->parallel()) {
                    $work[$name]();
                }
            }
            if ($this->parallel()) {
                $this->jobs->updateAll($work);
            }
        } finally {
            $this->jobs->waitForUnfinishedJobs();
            $this->files->cleanup();
        }
        return $this->state->failed ? 2 : ($this->state->needsUpdate ? 1 : 0);
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    private function buildRule(
        Target $target,
        BuildRule $rule,
        ?int $modifiedAt,
        bool &$executed,
        VariableScope $parent,
        PrerequisiteChain $chain,
        bool $grouped = false,
        bool $checking = false,
    ): UpdateResult {
        $key = $rule->recipe === null ? '' : spl_object_id($rule->recipe) . ':' . implode("\0", $rule->group);
        if ($rule->group !== [] && isset($this->state->recipes[$key])) {
            return $this->state->recipes[$key];
        }
        if (!$checking && $rule->group !== [] && !$grouped && $this->parallel()) {
            return $this->jobs->update(
                '@group:' . $key,
                /**
                 * @throws MakefileErrorException
                 * @throws CommandFailedException
                 */
                function () use ($target, $rule, $modifiedAt, &$executed, $parent, $chain): UpdateResult {
                    return $this->buildRule($target, $rule, $modifiedAt, $executed, $parent, $chain, true);
                },
            );
        }
        $rule = SecondaryExpansion::explicit(
            $target->name,
            $rule,
            new VariableExpander($chain->scope->context, $this->output, scope: $chain->scope),
            $this->filesystem,
        );
        [$prerequisites, $changed, $failure] = $this->dependencies(
            $target->name,
            $rule->prerequisites->merge(ExtraPrerequisites::forTarget(
                $target->name,
                $this->makefile,
                $this->context,
                $this->filesystem,
                $this->output,
            )),
            $executed,
            $chain,
            $modifiedAt,
        );
        if ($failure !== null) {
            return new UpdateResult(failure: $failure, blocked: true);
        }
        if ($checking) {
            foreach ([...$prerequisites->normal, ...$prerequisites->extra] as $dependency) {
                $time = $this->files->time($dependency);
                if ($time !== null && ($modifiedAt === null || $time > $modifiedAt)) {
                    return new UpdateResult(true);
                }
            }
            return new UpdateResult(
                $changed !== []
                || $this->files->time($target->name) !== null
                && ($modifiedAt === null || $this->files->time($target->name) > $modifiedAt),
            );
        }
        $rule = new BuildRule(
            $prerequisites,
            $rule->recipe,
            $rule->doubleColon,
            $rule->stem,
            $rule->group,
            $rule->firstPrerequisite,
            $rule->implicit,
        );
        $peers = $this->groupMembers($target, $rule, $chain);
        $peerChanged = $this->groupPrerequisites($target, $rule, $peers, $executed, $parent, $chain);
        if ($peerChanged->failure !== null) {
            return $peerChanged;
        }
        $alwaysMake = $this->options->alwaysMake && (!$this->state->remaking || $this->state->restarts === 0);
        $rebuild =
            $target->isPhony
            || $alwaysMake && $rule->recipe !== null
            || $peerChanged->changed
            || $changed !== []
            || $rule->doubleColon && $prerequisites->normal === [] && $prerequisites->orderOnly === [];
        foreach ($rule->implicit ? [$target->name] : $peers as $peer) {
            $rebuild =
                $rebuild
                || $this->files->needsRebuild($rule, $peer === $target->name ? $modifiedAt : $this->files->time($peer));
        }
        if (!$rebuild) {
            DebugTrace::write(
                $this->options->reporting,
                $this->output,
                'v',
                "No need to remake target '{$target->name}'.",
                count($chain->ancestors) - 1,
            );
            return new UpdateResult();
        }
        foreach (array_unique(DependencyOrder::arrange(
            $prerequisites->sequence,
            $this->options->parallel,
            $this->makefile,
            $target->name,
        )) as $dependency) {
            if (
                $dependency !== '.WAIT'
                && $this->files->intermediate($dependency)
                && !isset($chain->ancestors[$dependency])
            ) {
                $update =
                    /**
                     * @throws MakefileErrorException
                     * @throws CommandFailedException
                     */
                    function () use ($dependency, &$executed, $chain, $target): UpdateResult {
                        return $this->update($dependency, $executed, clone $chain, $target->name);
                    };
                $result = $this->parallel() ? $this->jobs->update($dependency, $update) : $update();
                if ($result->failure !== null) {
                    return new UpdateResult(failure: $result->failure, blocked: true);
                }
                if (
                    $result->changed
                    && in_array($dependency, [...$prerequisites->normal, ...$prerequisites->extra], true)
                ) {
                    $changed[] = $dependency;
                }
            }
        }
        DebugTrace::write(
            $this->options->reporting,
            $this->output,
            'b',
            "Must remake target '{$target->name}'.",
            count($chain->ancestors) - 1,
        );
        foreach ($peers as $peer) {
            $this->files->prepare(
                $peer,
                new VariableExpander($chain->scope->context, $this->output, scope: $chain->scope),
            );
        }
        $slot = $this->parallel() ? $this->jobs->acquire() : '';
        try {
            if ($this->state->remaking) {
                $this->context->loadedObjects->unload($target->name);
            }
            $ran = $this->runner->run(
                $target,
                $rule,
                $modifiedAt,
                $alwaysMake ? $prerequisites->normal : $changed,
                $chain->scope,
                $this->state->remaking ? $this->options->forMakefiles($this->state->restarts) : $this->options,
                !$this->state->remaking,
            );
        } catch (CommandFailedException $error) {
            if ($rule->group !== []) {
                $this->state->recipes[$key] = new UpdateResult(failure: $error);
            }
            throw $error;
        } finally {
            if ($this->parallel()) {
                $this->jobs->release($slot);
            }
        }
        $this->search->refresh($target, $chain->scope);
        DebugTrace::write(
            $this->options->reporting,
            $this->output,
            'b',
            "Successfully remade target file '{$target->name}'.",
            count($chain->ancestors) - 1,
        );
        $executed = $executed || $ran->active;
        $this->state->needsUpdate = $this->state->needsUpdate || $ran->needsUpdate;
        if ($ran->simulated) {
            foreach ($peers as $peer) {
                $this->state->simulated[$this->files->path($peer)] = true;
            }
        }
        $updated = new UpdateResult($ran->active || $target->isPhony || !$this->filesystem->exists($target->name));
        if ($rule->group !== []) {
            $this->state->recipes[$key] = $updated;
            foreach ($peers as $peer) {
                if (
                    $rule->implicit
                    && !$ran->simulated
                    && $this->filesystem->lastModified($target->name) !== null
                    && ($modifiedAt === null || $this->filesystem->lastModified($target->name) > $modifiedAt)
                    && !$this->filesystem->exists($peer)
                ) {
                    $this->output->writeWarning(
                        "warning: pattern recipe did not update peer target '{$peer}'.",
                        $rule->recipe?->source,
                    );
                }
                if ($rule->implicit && !isset($this->search->state->targets[$peer])) {
                    $this->search->state->targets[$peer] = new Target($peer, [$rule]);
                }
                if (count($this->search->resolve($peer, $chain->scope)->rules ?? []) === 1) {
                    $this->state->results[$peer] = $updated;
                }
            }
        }
        return $updated;
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     *
     * @return array{Prerequisites, list<string>, MakefileErrorException|CommandFailedException|null}
     */
    private function dependencies(
        string $name,
        Prerequisites $prerequisites,
        bool &$executed,
        PrerequisiteChain $chain,
        ?int $threshold = null,
    ): array {
        $normal = [];
        $orderOnly = [];
        $changed = [];
        $failure = null;
        $work = [];
        $updates = [];
        $serial = !$this->parallel($name);
        foreach (DependencyOrder::arrange(
            $prerequisites->sequence,
            $this->options->parallel,
            $this->makefile,
            $name,
        ) as $dependency) {
            if ($dependency === '.WAIT') {
                if ($work !== []) {
                    $updates += $this->jobs->updateAll($work);
                    $work = [];
                }
                continue;
            }
            if (isset($this->state->dropped[$name][$dependency])) {
                continue;
            }
            if (isset($chain->ancestors[$dependency])) {
                $this->output->writeWarning("Circular {$name} <- {$dependency} dependency dropped.");
                $this->state->dropped[$name][$dependency] = true;
                continue;
            }
            $branch = clone $chain;
            $work[$dependency] =
                /**
                 * @throws MakefileErrorException
                 * @throws CommandFailedException
                 */
                function () use ($dependency, &$executed, $branch, $name, $threshold): UpdateResult {
                    return $this->update($dependency, $executed, $branch, $name, $threshold, checking: true);
                };
            if ($serial) {
                $updates[$dependency] = $this->parallel()
                    ? $this->jobs->update($dependency, $work[$dependency])
                    : $work[$dependency]();
                $work = [];
            }
        }
        if ($work !== []) {
            $updates += $this->jobs->updateAll($work);
        }
        foreach ($updates as $dependency => $updated) {
            if ($updated->circular) {
                continue;
            }
            $failure ??= $updated->failure;
            if (in_array($dependency, [...$prerequisites->normal, ...$prerequisites->extra], true)) {
                if (
                    $updated->changed
                    && (
                        $threshold === null
                        || $this->files->intermediate($dependency)
                        || $this->files->time($dependency) === null
                        || $this->files->time($dependency) > $threshold
                        || ($this->makefile->targetsByName[$dependency]->isPhony ?? false)
                    )
                ) {
                    $changed[] = $dependency;
                }
            } else {
                $orderOnly[] = $dependency;
            }
        }
        foreach ($prerequisites->normal as $dependency) {
            if (
                !isset($this->state->dropped[$name][$dependency])
                && !isset($chain->ancestors[$dependency])
                && !($updates[$dependency]->circular ?? false)
            ) {
                $normal[] = $dependency;
            }
        }
        return [
            new Prerequisites(
                $normal,
                $orderOnly,
                $prerequisites->expressions,
                $prerequisites->sequence,
                extra: $prerequisites->extra,
            ),
            $changed,
            $failure,
        ];
    }

    /**
     * @throws MakefileErrorException
     *
     * @return list<string>
     */
    private function groupMembers(Target $target, BuildRule $rule, PrerequisiteChain $chain): array
    {
        $members = [$target->name];
        foreach ($rule->group as $peer) {
            if ($peer === $target->name) {
                continue;
            }
            if ($rule->implicit && ($this->makefile->targetsByName[$peer]->rules[0]->recipe ?? null) === null) {
                $members[] = $peer;
                continue;
            }
            foreach ($this->search->resolve($peer, $chain->scope)->rules ?? [] as $other) {
                if ($other->recipe === $rule->recipe && $other->group === $rule->group) {
                    $members[] = $peer;
                    break;
                }
            }
        }
        return $members;
    }

    /**
     * @param list<string> $peers
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    private function groupPrerequisites(
        Target $target,
        BuildRule $rule,
        array $peers,
        bool &$executed,
        VariableScope $inherited,
        PrerequisiteChain $chain,
    ): UpdateResult {
        $changed = false;
        foreach ($peers as $peer) {
            if ($peer === $target->name || isset($this->state->results[$peer])) {
                continue;
            }
            $parent = $chain->scope;
            $chain->scope = $this->makefile->targetVariables->scope($peer, $inherited->inherit(), $this->output);
            $chain->ancestors[$peer] = true;
            try {
                $rules = [];
                foreach ($rule->implicit
                    ? $this->makefile->targetsByName[$peer]->rules ?? []
                    : $this->search->resolve($peer, $chain->scope)->rules ?? [] as $other) {
                    if ($rule->implicit || $other->recipe === $rule->recipe && $other->group === $rule->group) {
                        $other = SecondaryExpansion::explicit(
                            $peer,
                            $other,
                            new VariableExpander($chain->scope->context, $this->output, scope: $chain->scope),
                            $this->filesystem,
                        );
                        [, $updated, $failure] = $this->dependencies($peer, $other->prerequisites, $executed, $chain);
                        if ($failure !== null) {
                            return new UpdateResult(failure: $failure, blocked: true);
                        }
                        $changed =
                            $changed
                            || $updated !== []
                            || !$rule->implicit && $this->files->needsRebuild($other, $this->files->time($peer));
                    }
                    $rules[] = $other;
                }
                if (!$rule->implicit) {
                    $this->search->state->targets[$peer] = new Target(
                        $peer,
                        $rules,
                        $this->search->resolve($peer, $chain->scope)->isPhony ?? false,
                    );
                }
            } finally {
                unset($chain->ancestors[$peer]);
                $chain->scope = $parent;
            }
        }
        return new UpdateResult($changed);
    }

    private function parallel(?string $name = null): bool
    {
        if ($this->options->parallel->jobs === 1) {
            return false;
        }
        return !DependencyOrder::serial($this->makefile, $name);
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    private function update(
        string $name,
        bool &$executed,
        PrerequisiteChain $chain,
        ?string $neededBy = null,
        ?int $threshold = null,
        bool $force = false,
        bool $checking = false,
    ): UpdateResult {
        if (array_key_exists($name, $this->state->results)) {
            if (
                $this->state->results[$name]->failure !== null
                && !$this->options->keepGoing
                && !$this->state->remaking
            ) {
                throw $this->state->results[$name]->failure;
            }
            return $this->state->results[$name];
        }
        DebugTrace::write(
            $this->options->reporting,
            $this->output,
            'v',
            "Considering target file '{$name}'.",
            count($chain->ancestors),
        );
        if ($this->files->time($name) === null) {
            DebugTrace::write(
                $this->options->reporting,
                $this->output,
                'b',
                " File '{$name}' does not exist.",
                count($chain->ancestors),
            );
        }
        $parent = $chain->scope;
        $chain->scope = $this->makefile->targetVariables->scope($name, $parent->inherit(), $this->output);
        try {
            if ($this->files->assumedOld($name)) {
                return $this->state->results[$name] = new UpdateResult();
            }
            $target = $this->search->resolve($name, $chain->scope);
            if ($target === null) {
                if ($this->files->time($name) !== null) {
                    return $this->state->results[$name] = new UpdateResult();
                }
                throw new MissingTargetException($name, $neededBy);
            }
            $modifiedAt = $force ? null : $this->files->time($name);
            $deferred = $checking && $this->files->intermediate($name);
            $chain->ancestors[$name] = true;
            try {
                $changed = false;
                $failure = null;
                foreach ($target->rules as $rule) {
                    try {
                        $updated = $this->buildRule(
                            $target,
                            $rule,
                            $deferred ? $threshold : $modifiedAt,
                            $executed,
                            $parent,
                            $chain,
                            checking: $deferred,
                        );
                    } catch (MissingTargetException|CommandFailedException $error) {
                        if (!$this->options->keepGoing && !$this->state->remaking) {
                            if ($this->parallel() && $error instanceof CommandFailedException) {
                                $this->state->failure($error, $this->output);
                                if ($this->jobs->running > 0 && !$this->state->waiting) {
                                    $this->state->waiting = true;
                                    $this->output->writeWarning('*** Waiting for unfinished jobs....');
                                }
                            }
                            throw $error;
                        }
                        $updated = $this->state->failure($error, $this->output);
                    }
                    if ($updated->failure !== null) {
                        if (!$this->options->keepGoing && !$this->state->remaking) {
                            throw $updated->failure;
                        }
                        if (!$updated->blocked) {
                            $this->state->failure($updated->failure, $this->output);
                        }
                        $failure ??= $updated;
                    }
                    $changed = $changed || $updated->changed;
                }
                if ($failure !== null) {
                    return $this->state->results[$name] = $failure;
                }
                if ($deferred) {
                    return new UpdateResult($changed);
                }
                return $this->state->results[$name] = new UpdateResult($changed);
            } finally {
                unset($chain->ancestors[$name]);
            }
        } catch (MissingTargetException|CommandFailedException $error) {
            if (!$this->state->remaking && !$this->options->keepGoing) {
                throw $error;
            }
            return $this->state->results[$name] = $this->state->failure($error, $this->output);
        } finally {
            $chain->scope = $parent;
        }
    }
}
