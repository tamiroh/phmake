<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Scheduling;

use Closure;
use LogicException;
use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\Execution\UpdateResult;
use Tamiroh\Phmake\Makefile\IO\JobSlots;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\TargetUpdates;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use WeakMap;

/**
 * Let target updates proceed while running jobs are waiting.
 *
 * @internal
 */
final class Jobs
{
    /** @var array<string, TargetUpdate> */
    private array $updates = [];

    /**
     * Updates started here and not yet collected, to tell them from other builds' updates.
     *
     * @var WeakMap<TargetUpdate, true>
     */
    private readonly WeakMap $started;

    private MakefileErrorException|CommandFailedException|null $error = null;

    public private(set) int $running = 0;

    /** Whether all job slots were taken since the last round or release. */
    private bool $slotsTaken = false;

    /** @var Closure(): bool */
    private readonly Closure $slotAvailable;

    public function __construct(
        private readonly TargetUpdates $execution,
        private readonly ?JobSlots $slots = null,
        private readonly ?Output $output = null,
    ) {
        $this->started = new WeakMap();
        $this->slotAvailable = fn(): bool => $this->error !== null || !$this->slotsTaken;
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
            // Later waiters would find no slot either, so only one of them retries per round.
            $this->slotsTaken = true;
            $this->execution->waitUntil($this->slotAvailable);
        } while (true);
    }

    public function release(string $slot): void
    {
        $this->slots?->release($slot);
        $this->running--;
        $this->slotsTaken = false;
    }

    /**
     * @param Closure(): UpdateResult $work
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function update(string $name, Closure $work): UpdateResult
    {
        return $this->updateAll([$name => $work])[$name] ?? throw new LogicException('Target update result is missing');
    }

    /**
     * @param array<string, Closure(): UpdateResult> $work
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     *
     * @return array<string, UpdateResult>
     */
    public function updateAll(array $work): array
    {
        $current = $this->execution->current();
        $owner = $current !== null && isset($this->started[$current]) ? $current : null;
        $pending = [];
        foreach ($work as $name => $callback) {
            if ($this->error !== null) {
                throw $this->error;
            }
            if (!isset($this->updates[$name]) || $this->updates[$name]->finished) {
                $this->updates[$name] = $this->execution->create($callback);
                $this->started[$this->updates[$name]] = true;
                $this->execution->proceed($this->updates[$name]);
                $this->error ??= $this->updates[$name]->error;
            }
            $pending[$name] = $this->updates[$name];
        }
        $results = [];
        foreach ($pending as $name => $update) {
            if ($owner !== null && $update->waitsFor($owner)) {
                foreach ($this->updates as $parent => $candidate) {
                    if ($candidate === $owner) {
                        $this->output?->writeWarning("Circular {$parent} <- {$name} dependency dropped.");
                        break;
                    }
                }
                $results[$name] = new UpdateResult(circular: true);
                continue;
            }
            if ($owner !== null) {
                $owner->waiting = $update;
            }
            while (!$update->finished) {
                if ($current === null) {
                    $this->proceed();
                } else {
                    $this->execution->waitUntil(static fn(): bool => $update->finished);
                }
            }
            if ($owner !== null) {
                $owner->waiting = null;
            }
            if ($update->error !== null) {
                throw $update->error;
            }
            $results[$name] = $update->result ?? throw new LogicException('Target update finished without a result');
            if (($this->updates[$name] ?? null) === $update) {
                unset($this->updates[$name], $this->started[$update]);
            }
        }
        return $results;
    }

    /**
     * Finish running jobs before cleaning up intermediates or returning an error.
     */
    public function waitForUnfinishedJobs(): void
    {
        do {
            $running = false;
            foreach ($this->updates as $update) {
                $running = $running || !$update->finished;
            }
            if ($running) {
                $this->proceed();
            }
        } while ($running);
    }

    /**
     * Let each target update that can proceed run until it waits again.
     */
    private function proceed(): void
    {
        $this->slotsTaken = false;
        foreach ($this->updates as $update) {
            // Skip readiness checks for prerequisite and slot waiters that would fail them.
            if (
                $update->waiting !== null && !$update->waiting->finished
                || $update->ready === $this->slotAvailable && $this->slotsTaken && $this->error === null
            ) {
                continue;
            }
            if (!$update->finished && ($update->ready === null || ($update->ready)())) {
                $this->execution->proceed($update);
                $this->error ??= $update->error;
            }
        }
        $this->slots?->waitForJobs();
    }
}
