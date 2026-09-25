<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\Evaluation\AutomaticVariables;
use Tamiroh\Phmake\Makefile\Evaluation\EvaluationContext;
use Tamiroh\Phmake\Makefile\Evaluation\SecondaryExpansion;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\Evaluation\VariableScope;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;
use Tamiroh\Phmake\Makefile\Rule\Target;
use Tamiroh\Phmake\Makefile\Search\RuleSearch;

use function array_key_exists;
use function array_map;
use function array_unique;
use function count;
use function implode;
use function in_array;
use function ltrim;
use function spl_object_id;
use function trim;

/**
 * Execution state belongs to one invocation, independently of the parsed rules.
 */
final class Build
{
    /** @var array<string, bool> */
    private array $results = [];

    /** @var array<string, true> */
    private array $visiting = [];

    /** @var array<string, bool|CommandFailedException> */
    private array $completedRecipes = [];

    private readonly RuleSearch $search;

    private VariableScope $scope;

    private readonly BuildFiles $files;

    /**
     * @throws MakefileErrorException
     */
    public function __construct(
        private readonly Makefile $makefile,
        private readonly Shell $shell,
        private readonly Filesystem $filesystem,
        private readonly Output $output,
    ) {
        $this->search = new RuleSearch($makefile, $filesystem, $output);
        $this->files = new BuildFiles($makefile, $this->search, $filesystem, $output);
        $this->scope = new VariableScope($makefile->context ?? new EvaluationContext($makefile->variables));
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
     * @return array<string, MakefileErrorException|CommandFailedException>
     */
    public function remake(array $names, array $unreadable = []): array
    {
        $this->files->goals = $names;
        $errors = [];
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
            $executed = false;
            try {
                $this->update($name, $executed, force: in_array($name, $unreadable, true));
            } catch (MakefileErrorException|CommandFailedException $error) {
                $errors[$name] = $error;
            }
        }
        return $errors;
    }

    /**
     * @param list<string> $names
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(array $names): void
    {
        try {
            if ($names === []) {
                if ($this->makefile->defaultGoal === null) {
                    throw new MakefileErrorException('No targets');
                }
                $names = [$this->makefile->defaultGoal];
            }
            $this->files->goals = $names;
            foreach ($names as $name) {
                $executed = false;
                $this->update($name, $executed);
                if (!$executed) {
                    $target = $this->search->resolve($name, $this->scope);
                    $this->output->writeInfo(
                        $target === null || $target->isPhony || ($target->rules[0]->recipe ?? null) === null
                            ? "Nothing to be done for '$name'."
                            : "'$name' is up to date.",
                    );
                }
            }
        } finally {
            $this->files->cleanup();
        }
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
    ): bool {
        $key = $rule->recipe === null ? '' : spl_object_id($rule->recipe) . ':' . implode("\0", $rule->group);
        if ($rule->group !== [] && isset($this->completedRecipes[$key])) {
            if ($this->completedRecipes[$key] instanceof CommandFailedException) {
                throw $this->completedRecipes[$key];
            }
            return $this->completedRecipes[$key];
        }
        $rule = SecondaryExpansion::explicit(
            $target->name,
            $rule,
            new VariableExpander($this->scope->context, $this->output, scope: $this->scope),
            $this->filesystem,
        );
        [$prerequisites, $changed] = $this->dependencies($target->name, $rule->prerequisites, $executed, $modifiedAt);
        $rule = new BuildRule(
            $prerequisites,
            $rule->recipe,
            $rule->doubleColon,
            $rule->stem,
            $rule->group,
            $rule->firstPrerequisite,
            $rule->implicit,
        );
        $peers = $this->groupMembers($target, $rule);
        $peerChanged = $this->groupPrerequisites($target, $rule, $peers, $executed, $parent);
        $rebuild =
            $target->isPhony
            || $peerChanged
            || $changed !== []
            || $rule->doubleColon && $prerequisites->normal === [] && $prerequisites->orderOnly === [];
        foreach ($peers as $peer) {
            $rebuild =
                $rebuild
                || $this->files->needsRebuild($rule, $peer === $target->name ? $modifiedAt : $this->files->time($peer));
        }
        if (!$rebuild) {
            return false;
        }
        foreach (array_unique($prerequisites->sequence) as $dependency) {
            if ($this->files->intermediate($dependency) && !isset($this->visiting[$dependency])) {
                if (
                    $this->update($dependency, $executed, $target->name)
                    && in_array($dependency, $prerequisites->normal, true)
                ) {
                    $changed[] = $dependency;
                }
            }
        }
        $this->files->prepare(
            $target->name,
            new VariableExpander($this->scope->context, $this->output, scope: $this->scope),
        );
        try {
            $ran = $this->execute($target, $rule, $modifiedAt, $changed);
        } catch (CommandFailedException $error) {
            if ($rule->group !== []) {
                $this->completedRecipes[$key] = $error;
            }
            throw $error;
        }
        $executed = $executed || $ran;
        $updated = $ran || $target->isPhony || !$this->filesystem->exists($target->name);
        if ($rule->group !== []) {
            $this->completedRecipes[$key] = $updated;
            foreach ($peers as $peer) {
                if ($rule->implicit && !isset($this->search->state->targets[$peer])) {
                    $this->search->state->targets[$peer] = new Target($peer, [$rule]);
                }
                if (count($this->search->resolve($peer, $this->scope)->rules ?? []) === 1) {
                    $this->results[$peer] = $updated;
                }
            }
        }
        return $updated;
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     *
     * @return array{Prerequisites, list<string>}
     */
    private function dependencies(
        string $name,
        Prerequisites $prerequisites,
        bool &$executed,
        ?int $threshold = null,
    ): array {
        $normal = [];
        $orderOnly = [];
        $changed = [];
        foreach (array_unique($prerequisites->sequence) as $dependency) {
            if (isset($this->visiting[$dependency])) {
                $this->output->writeWarning("Circular $name <- $dependency dependency dropped.");
                continue;
            }
            $updated = $this->update($dependency, $executed, $name, $threshold);
            if (in_array($dependency, $prerequisites->normal, true)) {
                if (
                    $updated
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
            if (!isset($this->visiting[$dependency])) {
                $normal[] = $dependency;
            }
        }
        return [
            new Prerequisites($normal, $orderOnly, $prerequisites->expressions, $prerequisites->sequence),
            $changed,
        ];
    }

    /**
     * @param list<string> $changed
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    private function execute(Target $target, BuildRule $rule, ?int $modifiedAt, array $changed): bool
    {
        $expander = new VariableExpander(
            $this->scope->context,
            $this->output,
            scope: $this->scope,
        )->withVariables(AutomaticVariables::forRule(
            $this->files->path($target->name),
            $this->files->mapped($rule),
            $modifiedAt,
            array_map($this->files->path(...), $changed),
            $this->filesystem,
        ));
        $commands = [];
        foreach ($rule->recipe->commands ?? [] as $command) {
            $commands[] = $command->expand($expander, $this->output);
        }
        $shell = new ExportingShell($this->shell, $this->makefile->exports, $expander, $this->output);
        $ran = false;
        foreach ($commands as $command) {
            $status = $command->run($shell, $this->output);
            if ($status !== 0) {
                throw new CommandFailedException($target->name, $status);
            }
            $ran = $ran || trim(ltrim($command->expression, "@-+ \t\n")) !== '';
        }
        return $ran;
    }

    /**
     * @throws MakefileErrorException
     *
     * @return list<string>
     */
    private function groupMembers(Target $target, BuildRule $rule): array
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
            foreach ($this->search->resolve($peer, $this->scope)->rules ?? [] as $other) {
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
    ): bool {
        if ($rule->implicit) {
            return false;
        }
        $changed = false;
        foreach ($peers as $peer) {
            if ($peer === $target->name || isset($this->results[$peer])) {
                continue;
            }
            $parent = $this->scope;
            $this->scope = $this->makefile->scopes->scope($peer, $inherited->inherit(), $this->output);
            $this->visiting[$peer] = true;
            try {
                $rules = [];
                foreach ($this->search->resolve($peer, $this->scope)->rules ?? [] as $other) {
                    if ($other->recipe === $rule->recipe && $other->group === $rule->group) {
                        $other = SecondaryExpansion::explicit(
                            $peer,
                            $other,
                            new VariableExpander($this->scope->context, $this->output, scope: $this->scope),
                            $this->filesystem,
                        );
                        [, $updated] = $this->dependencies($peer, $other->prerequisites, $executed);
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
                    $this->search->resolve($peer, $this->scope)->isPhony ?? false,
                );
            } finally {
                unset($this->visiting[$peer]);
                $this->scope = $parent;
            }
        }
        return $changed;
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    private function update(
        string $name,
        bool &$executed,
        ?string $neededBy = null,
        ?int $threshold = null,
        bool $force = false,
    ): bool {
        if (array_key_exists($name, $this->results)) {
            return $this->results[$name];
        }
        $parent = $this->scope;
        $this->scope = $this->makefile->scopes->scope($name, $parent->inherit(), $this->output);
        try {
            $target = $this->search->resolve($name, $this->scope);
            if ($target === null) {
                if ($this->files->time($name) !== null) {
                    return $this->results[$name] = false;
                }
                throw new MakefileErrorException(
                    "No rule to make target '$name'" . ($neededBy === null ? '' : ", needed by '$neededBy'"),
                );
            }
            $modifiedAt = $force ? null : $this->files->time($name);
            $deferred = $modifiedAt === null && $threshold !== null && $this->files->intermediate($name);
            $this->visiting[$name] = true;
            try {
                $changed = false;
                foreach ($target->rules as $rule) {
                    $updated = $this->buildRule(
                        $target,
                        $rule,
                        $deferred ? $threshold : $modifiedAt,
                        $executed,
                        $parent,
                    );
                    $changed = $changed || $updated;
                }
                if ($deferred && !$changed) {
                    return false;
                }
                return $this->results[$name] = $changed;
            } finally {
                unset($this->visiting[$name]);
            }
        } finally {
            $this->scope = $parent;
        }
    }
}
