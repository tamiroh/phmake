<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_map;
use function implode;
use function in_array;
use function sort;
use function str_starts_with;

/** File names, timestamps, and intermediate lifetime for one build invocation. */
final class BuildFiles
{
    /** @var list<string> */
    public array $goals = [];

    /** @var array<string, string> */
    private array $created = [];

    private readonly FilePolicy $policy;

    /** @throws MakefileErrorException */
    public function __construct(
        private readonly Makefile $makefile,
        private readonly RuleSearch $search,
        private readonly Filesystem $filesystem,
        private readonly Output $output,
    ) {
        $this->policy = new FilePolicy($makefile->targetsByName);
    }

    public function cleanup(): void
    {
        $removed = [];
        foreach ($this->created as $path) {
            if ($this->filesystem->exists($path) && $this->filesystem->remove($path)) {
                $removed[] = $path;
            }
        }
        if ($removed !== []) {
            sort($removed);
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
        foreach ($rule->prerequisites->normal as $dependency) {
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

    /** @throws MakefileErrorException */
    public function prepare(string $name, VariableExpander $expander): void
    {
        $path = $this->path($name);
        if (
            $path !== $name
            && !str_starts_with($name, '-l')
            && !isset($this->makefile->targetsByName[$path])
            && !$this->makefile->paths->retain($path, $expander)
        ) {
            unset($this->search->state->paths[$name]);
        }
        if (
            $this->intermediate($name)
            && !($this->search->state->existed[$name] ?? false)
            && !$this->policy->keep($name)
        ) {
            $this->created[$name] = $this->path($name);
        }
    }

    public function time(string $name): ?int
    {
        return $this->filesystem->lastModified($this->path($name));
    }
}
