<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Scheduling;

use Closure;
use Fiber;
use LogicException;
use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\Execution\UpdateResult;
use Tamiroh\Phmake\Makefile\IO\JobSlots;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use WeakMap;

/**
 * Cooperatively run dependency traversals while recipe processes are waiting.
 *
 * @internal
 */
final class Scheduler
{
    /** @var array<string, BuildTask> */
    private array $tasks = [];

    /** @var WeakMap<object, BuildTask> */
    private readonly WeakMap $fibers;

    private MakefileErrorException|CommandFailedException|null $error = null;

    public private(set) int $running = 0;

    /** Whether a slot probe failed since the last tick or release. */
    private bool $exhausted = false;

    /** @var Closure(): bool */
    private readonly Closure $slotReady;

    public function __construct(
        private readonly ?JobSlots $slots = null,
        private readonly ?Output $output = null,
    ) {
        $this->fibers = new WeakMap();
        $this->slotReady = fn(): bool => $this->error !== null || !$this->exhausted;
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function acquire(): string
    {
        do {
            if ($this->error !== null) {
                throw $this->error;
            }
            $slot = $this->slots?->acquire() ?? ($this->slots === null ? '' : null);
            if ($slot !== null) {
                $this->running++;
                return $slot;
            }
            // Later waiters would fail the same probe, so only one of them retries per tick.
            $this->exhausted = true;
            Suspension::until($this->slotReady);
        } while (true);
    }

    /**
     * @param Closure(): UpdateResult $work
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function await(string $name, Closure $work): UpdateResult
    {
        return $this->join([$name => $work])[$name] ?? throw new LogicException('Build task result is missing');
    }

    /**
     * Finish running commands before cleaning up intermediates or returning an error.
     */
    public function drain(): void
    {
        do {
            $running = false;
            foreach ($this->tasks as $task) {
                $running = $running || !$task->fiber->isTerminated();
            }
            if ($running) {
                $this->tick();
            }
        } while ($running);
    }

    /**
     * @param array<string, Closure(): UpdateResult> $work
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     *
     * @return array<string, UpdateResult>
     */
    public function join(array $work): array
    {
        $fiber = Fiber::getCurrent();
        $owner = $fiber === null ? null : $this->fibers[$fiber] ?? null;
        $pending = [];
        foreach ($work as $name => $callback) {
            if ($this->error !== null) {
                throw $this->error;
            }
            if (!isset($this->tasks[$name]) || $this->tasks[$name]->fiber->isTerminated()) {
                $this->tasks[$name] = new BuildTask($callback);
                $this->fibers[$this->tasks[$name]->fiber] = $this->tasks[$name];
                $this->tasks[$name]->advance();
                $this->error ??= $this->tasks[$name]->error;
            }
            $pending[$name] = $this->tasks[$name];
        }
        $results = [];
        foreach ($pending as $name => $task) {
            if ($owner !== null && $task->waitsFor($owner)) {
                foreach ($this->tasks as $parent => $candidate) {
                    if ($candidate === $owner) {
                        $this->output?->writeWarning("Circular {$parent} <- {$name} dependency dropped.");
                        break;
                    }
                }
                $results[$name] = new UpdateResult(circular: true);
                continue;
            }
            if ($owner !== null) {
                $owner->waiting = $task;
            }
            while (!$task->fiber->isTerminated()) {
                if (Fiber::getCurrent() === null) {
                    $this->tick();
                } else {
                    Suspension::until(static fn(): bool => $task->fiber->isTerminated());
                }
            }
            if ($owner !== null) {
                $owner->waiting = null;
            }
            if ($task->error !== null) {
                throw $task->error;
            }
            $results[$name] = $task->result ?? throw new LogicException('Build task completed without a result');
            if (($this->tasks[$name] ?? null) === $task) {
                unset($this->tasks[$name], $this->fibers[$task->fiber]);
            }
        }
        return $results;
    }

    public function release(string $slot): void
    {
        $this->slots?->release($slot);
        $this->running--;
        $this->exhausted = false;
    }

    private function tick(): void
    {
        $this->exhausted = false;
        foreach ($this->tasks as $task) {
            // Skip readiness calls for dependency and slot waiters that would fail them.
            if (
                $task->waiting !== null && !$task->waiting->fiber->isTerminated()
                || $task->ready === $this->slotReady && $this->exhausted && $this->error === null
            ) {
                continue;
            }
            if (!$task->fiber->isTerminated() && ($task->ready === null || ($task->ready)())) {
                $task->advance();
                $this->error ??= $task->error;
            }
        }
        $this->slots?->waitForJobs();
    }
}
