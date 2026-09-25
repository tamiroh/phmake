<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_key_exists;
use function array_map;
use function array_unique;
use function count;
use function implode;
use function in_array;
use function ltrim;
use function spl_object_id;
use function trim;

/** Execution state belongs to one invocation, independently of the parsed rules. */
final class Build
{
    /** @var array<string, bool> */
    private array $results = [];
    /** @var array<string, true> */
    private array $visiting = [];
    /** @var array<string, bool> */
    private array $completedRecipes = [];
    private readonly RuleSearch $search;
    private VariableScope $scope;
    private readonly BuildFiles $files;

    /** @throws MakefileErrorException */
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

    /** @param list<string> $names
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(array $names): void
    {
        if ($names === []) {
            if ($this->makefile->defaultGoal === null) {
                throw new MakefileErrorException('No targets');
            }
            $names = [$this->makefile->defaultGoal];
        }
        $this->files->goals = $names;
        try {
            foreach ($names as $name) {
                $executed = false;
                $this->update($name, $executed);
                if (!$executed) {
                    $target = $this->resolve($name);
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

    /** @throws MakefileErrorException
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
        $ran = $this->execute($target, $rule, $modifiedAt, $changed);
        $executed = $executed || $ran;
        $updated = $ran || $target->isPhony || !$this->filesystem->exists($target->name);
        if ($rule->group !== []) {
            $this->completedRecipes[$key] = $updated;
            foreach ($peers as $peer) {
                if ($rule->implicit && !isset($this->search->state->targets[$peer])) {
                    $this->search->state->targets[$peer] = new Target($peer, [$rule]);
                }
                if (count($this->resolve($peer)->rules ?? []) === 1) {
                    $this->results[$peer] = $updated;
                }
            }
        }
        return $updated;
    }

    /** @return array{Prerequisites, list<string>}
     * @throws MakefileErrorException
     * @throws CommandFailedException
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

    /** @param list<string> $changed
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

    /** @return list<string>
     * @throws MakefileErrorException
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
            foreach ($this->resolve($peer)->rules ?? [] as $other) {
                if ($other->recipe === $rule->recipe && $other->group === $rule->group) {
                    $members[] = $peer;
                    break;
                }
            }
        }
        return $members;
    }

    /** @param list<string> $peers
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
                foreach ($this->resolve($peer)->rules ?? [] as $other) {
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
                    $this->resolve($peer)->isPhony ?? false,
                );
            } finally {
                unset($this->visiting[$peer]);
                $this->scope = $parent;
            }
        }
        return $changed;
    }

    /** @throws MakefileErrorException */
    private function resolve(string $name): ?Target
    {
        return $this->search->resolve($name, $this->scope);
    }

    /** @throws MakefileErrorException
     * @throws CommandFailedException
     */
    private function update(string $name, bool &$executed, ?string $neededBy = null, ?int $threshold = null): bool
    {
        if (array_key_exists($name, $this->results)) {
            return $this->results[$name];
        }
        $parent = $this->scope;
        $this->scope = $this->makefile->scopes->scope($name, $parent->inherit(), $this->output);
        try {
            $target = $this->resolve($name);
            if ($target === null) {
                if ($this->files->time($name) !== null) {
                    return $this->results[$name] = false;
                }
                throw new MakefileErrorException(
                    "No rule to make target '$name'" . ($neededBy === null ? '' : ", needed by '$neededBy'"),
                );
            }
            $modifiedAt = $this->files->time($name);
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
