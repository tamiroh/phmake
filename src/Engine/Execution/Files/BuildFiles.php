<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Files;

use Tamiroh\Phmake\Engine\Execution\BuildState;
use Tamiroh\Phmake\Engine\Execution\ExecutionOptions;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\IntermediateDeletionOrder;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\ArchiveMember;
use Tamiroh\Phmake\Engine\Rule\BuildRule;
use Tamiroh\Phmake\Engine\Search\RuleSearch;

use function implode;
use function in_array;
use function preg_replace;
use function str_starts_with;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * File names, timestamps, and intermediate lifetime for one build invocation.
 *
 * @internal
 */
final class BuildFiles
{
    /** @var list<string> */
    public array $goals = [];

    /** @var array<string, array{string, string}> */
    private array $created = [];

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
        private readonly BuildState $state,
        private readonly IntermediateDeletionOrder $deletionOrder,
    ) {
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
            if ($path !== null && $this->filesystem->exists($path) && $this->filesystem->remove($path)) {
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

    public function precious(string $name): bool
    {
        return $this->makefile->special->contains('.PRECIOUS', $name) || isset($this->search->state->precious[$name]);
    }

    /**
     * @throws MakefileErrorException
     */
    public function prepare(string $name, VariableExpander $expander): void
    {
        $this->deletionOrder->enter($name);
        $path = $this->path($name);
        if (
            $path !== $name
            && !str_starts_with($name, '-l')
            && !isset($this->makefile->targetsByName[$path])
            && !$this->makefile->searchPaths->retain($path, $expander)
        ) {
            unset($this->search->state->paths[$name]);
            $this->search->state->discardedPaths[$name] = true;
        }
        $this->search->state->existed[$name] ??= $this->filesystem->exists($this->path($name));
        if (!$this->search->state->existed[$name]) {
            $this->created[$name] = [$name, $this->path($name)];
        }
    }

    /**
     * Round low-resolution targets up within their second when comparing prerequisites.
     */
    public function targetTime(string $name): ?int
    {
        $time = $this->time($name);
        if ($time === null || $time === PHP_INT_MIN || $time === PHP_INT_MAX) {
            return $time;
        }
        if (ArchiveMember::parse($name) !== null || $this->makefile->special->contains('.LOW_RESOLUTION_TIME', $name)) {
            return $time + 999_999_999 - ((($time % 1_000_000_000) + 1_000_000_000) % 1_000_000_000);
        }
        return $time;
    }

    public function time(string $name): ?int
    {
        $path = $this->path($name);
        if ($this->assumedOld($name)) {
            return PHP_INT_MIN;
        }
        if ($this->makefile->targetsByName[$name]->isPhony ?? false) {
            return null;
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
