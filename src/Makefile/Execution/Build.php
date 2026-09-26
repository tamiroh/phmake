<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\Evaluation\EvaluationContext;
use Tamiroh\Phmake\Makefile\Evaluation\SecondaryExpansion;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\Evaluation\VariableScope;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\JobSlots;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;
use Tamiroh\Phmake\Makefile\Rule\Target;
use Tamiroh\Phmake\Makefile\Search\RuleSearch;

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

    private readonly BuildPath $path;

    private readonly Scheduler $scheduler;

    private readonly BuildFiles $files;

    /**
     * @throws MakefileErrorException
     */
    public function __construct(
        private readonly Makefile $makefile,
        Shell $shell,
        private readonly Filesystem $filesystem,
        private readonly Output $output,
        private readonly ExecutionOptions $options = new ExecutionOptions(),
        int $restarts = 0,
        ?JobSlots $slots = null,
    ) {
        $this->state = new BuildState($restarts);
        $this->search = new RuleSearch($makefile, $filesystem, $output);
        $this->files = new BuildFiles($makefile, $this->search, $filesystem, $output, $options, $this->state);
        $this->runner = new RecipeRunner($makefile, $shell, $filesystem, $output, $this->files);
        $this->path = new BuildPath(
            new VariableScope($makefile->context ?? new EvaluationContext($makefile->variables)),
        );
        $this->scheduler = new Scheduler($slots, $output);
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
        $this->state->remaking = true;
        $errors = [];
        $work = [];
        foreach (array_unique($names) as $name) {
            $target = $this->makefile->targetsByName[$name] ?? null;
            if ($target?->isPhony === true) {
                if (!$this->filesystem->exists($name)) {
                    $errors[$name] = new MakefileErrorException("No rule to make target '$name'");
                }
                continue;
            }
            foreach ($target->rules ?? [] as $rule) {
                if ($rule->doubleColon && $rule->prerequisites->sequence === [] && $rule->recipe !== null) {
                    if (!$this->filesystem->exists($name)) {
                        $errors[$name] = new MakefileErrorException("No rule to make target '$name'");
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
                        clone $this->path,
                        force: in_array($name, $unreadable, true),
                    );
                };
        }
        try {
            if ($this->parallel()) {
                $results = $this->scheduler->join($work);
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
            $this->scheduler->drain();
        }
        foreach ($this->state->results as $name => $result) {
            if ($result->failure !== null) {
                unset($this->state->results[$name]);
            }
        }
        $this->state->remaking = false;
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
                if ($this->makefile->defaultGoal === null) {
                    throw new MakefileErrorException('No targets');
                }
                $names = [$this->makefile->defaultGoal];
            }
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
                        $result = $this->update($name, $executed, clone $this->path);
                        if ($result->failure !== null) {
                            $this->state->failed = true;
                            if ($result->blocked) {
                                $this->output->writeWarning("Target '$name' not remade because of errors.");
                            }
                        } elseif (!$executed && !$this->options->question && !$this->options->silent) {
                            $target = $this->search->resolve($name, $this->path->scope);
                            $this->output->writeInfo(
                                $target === null || $target->isPhony || ($target->rules[0]->recipe ?? null) === null
                                    ? "Nothing to be done for '$name'."
                                    : "'$name' is up to date.",
                            );
                        }
                        return $result;
                    };
                if (!$this->parallel()) {
                    $work[$name]();
                }
            }
            if ($this->parallel()) {
                $this->scheduler->join($work);
            }
        } finally {
            $this->scheduler->drain();
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
        BuildPath $path,
        bool $grouped = false,
    ): UpdateResult {
        $key = $rule->recipe === null ? '' : spl_object_id($rule->recipe) . ':' . implode("\0", $rule->group);
        if ($rule->group !== [] && isset($this->state->recipes[$key])) {
            return $this->state->recipes[$key];
        }
        if ($rule->group !== [] && !$grouped && $this->parallel()) {
            return $this->scheduler->await(
                '@group:' . $key,
                /**
                 * @throws MakefileErrorException
                 * @throws CommandFailedException
                 */
                function () use ($target, $rule, $modifiedAt, &$executed, $parent, $path): UpdateResult {
                    return $this->buildRule($target, $rule, $modifiedAt, $executed, $parent, $path, true);
                },
            );
        }
        $rule = SecondaryExpansion::explicit(
            $target->name,
            $rule,
            new VariableExpander($path->scope->context, $this->output, scope: $path->scope),
            $this->filesystem,
        );
        [$prerequisites, $changed, $failure] = $this->dependencies(
            $target->name,
            $rule->prerequisites,
            $executed,
            $path,
            $modifiedAt,
        );
        if ($failure !== null) {
            return new UpdateResult(failure: $failure, blocked: true);
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
        $peers = $this->groupMembers($target, $rule, $path);
        $peerChanged = $this->groupPrerequisites($target, $rule, $peers, $executed, $parent, $path);
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
        foreach ($peers as $peer) {
            $rebuild =
                $rebuild
                || $this->files->needsRebuild($rule, $peer === $target->name ? $modifiedAt : $this->files->time($peer));
        }
        if (!$rebuild) {
            return new UpdateResult();
        }
        foreach (array_unique($prerequisites->sequence) as $dependency) {
            if (
                $dependency !== '.WAIT'
                && $this->files->intermediate($dependency)
                && !isset($path->visiting[$dependency])
            ) {
                $update =
                    /**
                     * @throws MakefileErrorException
                     * @throws CommandFailedException
                     */
                    function () use ($dependency, &$executed, $path, $target): UpdateResult {
                        return $this->update($dependency, $executed, clone $path, $target->name);
                    };
                $result = $this->parallel() ? $this->scheduler->await($dependency, $update) : $update();
                if ($result->failure !== null) {
                    return new UpdateResult(failure: $result->failure, blocked: true);
                }
                if ($result->changed && in_array($dependency, $prerequisites->normal, true)) {
                    $changed[] = $dependency;
                }
            }
        }
        $this->files->prepare(
            $target->name,
            new VariableExpander($path->scope->context, $this->output, scope: $path->scope),
        );
        $token = $this->parallel() ? $this->scheduler->acquire() : '';
        try {
            $ran = $this->runner->run(
                $target,
                $rule,
                $modifiedAt,
                $alwaysMake ? $prerequisites->normal : $changed,
                $path->scope,
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
                $this->scheduler->release($token);
            }
        }
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
                if ($rule->implicit && !isset($this->search->state->targets[$peer])) {
                    $this->search->state->targets[$peer] = new Target($peer, [$rule]);
                }
                if (count($this->search->resolve($peer, $path->scope)->rules ?? []) === 1) {
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
        BuildPath $path,
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
                    $updates += $this->scheduler->join($work);
                    $work = [];
                }
                continue;
            }
            if (isset($path->visiting[$dependency])) {
                $this->output->writeWarning("Circular $name <- $dependency dependency dropped.");
                continue;
            }
            $branch = clone $path;
            $work[$dependency] =
                /**
                 * @throws MakefileErrorException
                 * @throws CommandFailedException
                 */
                function () use ($dependency, &$executed, $branch, $name, $threshold): UpdateResult {
                    return $this->update($dependency, $executed, $branch, $name, $threshold);
                };
            if ($serial) {
                $updates[$dependency] = $this->parallel()
                    ? $this->scheduler->await($dependency, $work[$dependency])
                    : $work[$dependency]();
                $work = [];
            }
        }
        if ($work !== []) {
            $updates += $this->scheduler->join($work);
        }
        foreach ($updates as $dependency => $updated) {
            if ($updated->circular) {
                continue;
            }
            $failure ??= $updated->failure;
            if (in_array($dependency, $prerequisites->normal, true)) {
                if (
                    $updated->changed
                    && (
                        $threshold === null
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
            if (!isset($path->visiting[$dependency]) && !($updates[$dependency]->circular ?? false)) {
                $normal[] = $dependency;
            }
        }
        return [
            new Prerequisites($normal, $orderOnly, $prerequisites->expressions, $prerequisites->sequence),
            $changed,
            $failure,
        ];
    }

    /**
     * @throws MakefileErrorException
     *
     * @return list<string>
     */
    private function groupMembers(Target $target, BuildRule $rule, BuildPath $path): array
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
            foreach ($this->search->resolve($peer, $path->scope)->rules ?? [] as $other) {
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
        BuildPath $path,
    ): UpdateResult {
        if ($rule->implicit) {
            return new UpdateResult();
        }
        $changed = false;
        foreach ($peers as $peer) {
            if ($peer === $target->name || isset($this->state->results[$peer])) {
                continue;
            }
            $parent = $path->scope;
            $path->scope = $this->makefile->scopes->scope($peer, $inherited->inherit(), $this->output);
            $path->visiting[$peer] = true;
            try {
                $rules = [];
                foreach ($this->search->resolve($peer, $path->scope)->rules ?? [] as $other) {
                    if ($other->recipe === $rule->recipe && $other->group === $rule->group) {
                        $other = SecondaryExpansion::explicit(
                            $peer,
                            $other,
                            new VariableExpander($path->scope->context, $this->output, scope: $path->scope),
                            $this->filesystem,
                        );
                        [, $updated, $failure] = $this->dependencies($peer, $other->prerequisites, $executed, $path);
                        if ($failure !== null) {
                            return new UpdateResult(failure: $failure, blocked: true);
                        }
                        $changed =
                            $changed
                            || $updated !== []
                            || $this->files->needsRebuild($other, $this->files->time($peer));
                    }
                    $rules[] = $other;
                }
                $this->search->state->targets[$peer] = new Target(
                    $peer,
                    $rules,
                    $this->search->resolve($peer, $path->scope)->isPhony ?? false,
                );
            } finally {
                unset($path->visiting[$peer]);
                $path->scope = $parent;
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
        BuildPath $path,
        ?string $neededBy = null,
        ?int $threshold = null,
        bool $force = false,
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
        $parent = $path->scope;
        $path->scope = $this->makefile->scopes->scope($name, $parent->inherit(), $this->output);
        try {
            if ($this->files->assumedOld($name)) {
                return $this->state->results[$name] = new UpdateResult();
            }
            $target = $this->search->resolve($name, $path->scope);
            if ($target === null) {
                if ($this->files->time($name) !== null) {
                    return $this->state->results[$name] = new UpdateResult();
                }
                throw new MissingTargetException($name, $neededBy);
            }
            $modifiedAt = $force ? null : $this->files->time($name);
            $deferred = $modifiedAt === null && $threshold !== null && $this->files->intermediate($name);
            $path->visiting[$name] = true;
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
                            $path,
                        );
                    } catch (MissingTargetException|CommandFailedException $error) {
                        if (!$this->options->keepGoing && !$this->state->remaking) {
                            if ($this->parallel() && $error instanceof CommandFailedException) {
                                $this->state->failure($error, $this->output);
                                if ($this->scheduler->running > 0 && !$this->state->waiting) {
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
                if ($deferred && !$changed) {
                    return new UpdateResult();
                }
                return $this->state->results[$name] = new UpdateResult($changed);
            } finally {
                unset($path->visiting[$name]);
            }
        } catch (MissingTargetException|CommandFailedException $error) {
            if (!$this->state->remaking && !$this->options->keepGoing) {
                throw $error;
            }
            return $this->state->results[$name] = $this->state->failure($error, $this->output);
        } finally {
            $path->scope = $parent;
        }
    }
}
