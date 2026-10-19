<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Files;

use Tamiroh\Phmake\Engine\Execution\BuildState;
use Tamiroh\Phmake\Engine\Execution\ExecutionOptions;
use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\Rule\ArchiveMember;
use Tamiroh\Phmake\Engine\Search\RuleSearch;

use function preg_replace;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Directory-search identities and timestamp observations for one update invocation.
 * Speculative rule-search branches do not mutate these records.
 *
 * @internal
 */
final readonly class FileStates
{
    public function __construct(
        private Makefile $makefile,
        private RuleSearch $search,
        private Filesystem $filesystem,
        private ExecutionOptions $options,
        private BuildState $state,
        private Output $output,
    ) {}

    public function assumedNew(string $name): bool
    {
        if ($this->state->remaking && $this->state->restarts !== 0) {
            return false;
        }
        foreach ($this->options->files->newFiles as $file) {
            if ($this->matches($file, $name)) {
                return true;
            }
        }
        return false;
    }

    public function assumedOld(string $name): bool
    {
        foreach ($this->options->files->oldFiles as $file) {
            if ($this->matches($file, $name)) {
                return true;
            }
        }
        return false;
    }

    public function hasSpecial(string $special, string $name): bool
    {
        foreach ($this->record($name)->names as $alias => $_) {
            if ($this->makefile->special->contains($special, $alias)) {
                return true;
            }
        }
        return false;
    }

    public function path(string $name): string
    {
        return $this->search->state->paths[$name] ?? $name;
    }

    public function record(string $name): FileState
    {
        $path = $this->path($name);
        $canonical = $this->makefile->searchPaths->retain($path) || isset($this->makefile->targetsByName[$path])
            ? $path
            : $name;
        $previous = $this->state->files[$name] ?? null;
        $file = $this->state->files[$canonical] ??= $this->state->files[$name] ?? new FileState($path);
        if ($previous !== null && $previous !== $file) {
            foreach ($previous->names as $alias => $_) {
                $file->names[$alias] = true;
                $this->state->files[$alias] = $file;
            }
        }
        $file->names[$name] = $file->names[$canonical] = true;
        $this->state->files[$name] = $file;
        if ($file->path !== $path) {
            $file->path = $path;
            $file->observed = false;
        }
        return $file;
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
        if (ArchiveMember::parse($name) !== null || $this->hasSpecial('.LOW_RESOLUTION_TIME', $name)) {
            $nanoseconds = (($time % 1_000_000_000) + 1_000_000_000) % 1_000_000_000;
            if ($nanoseconds !== 0) {
                $this->output->writeWarning(
                    "*** Warning: .LOW_RESOLUTION_TIME file '{$name}' has a high resolution time stamp",
                );
            }
            return $time + 999_999_999 - $nanoseconds;
        }
        return $time;
    }

    public function time(string $name): ?int
    {
        $path = $this->path($name);
        if ($this->assumedOld($name)) {
            return PHP_INT_MIN;
        }
        if ($this->assumedNew($name)) {
            return PHP_INT_MAX;
        }
        if ($this->makefile->targetsByName[$name]->isPhony ?? false) {
            return null;
        }
        $file = $this->record($name);
        if ($file->simulated) {
            return PHP_INT_MAX;
        }
        if (!$file->observed) {
            $file->modifiedAt = $this->filesystem->lastModified($path);
            $file->observed = true;
        }
        return $file->modifiedAt;
    }

    private function matches(string $file, string $name): bool
    {
        $file = preg_replace('~^(?:\./)+~', '', $file);
        return (
            $file === preg_replace('~^(?:\./)+~', '', $name)
            || $file === preg_replace('~^(?:\./)+~', '', $this->path($name))
        );
    }
}
