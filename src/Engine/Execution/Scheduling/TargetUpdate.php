<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Scheduling;

use Closure;
use Tamiroh\Phmake\Engine\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Engine\Execution\UpdateResult;
use Tamiroh\Phmake\Engine\MakefileErrorException;

/**
 * The update of one target and its prerequisites, which can wait while other updates proceed.
 *
 * @internal
 */
final class TargetUpdate
{
    /** @var (Closure(): bool)|null */
    public ?Closure $ready = null;

    public ?UpdateResult $result = null;

    public ?self $waiting = null;

    public MakefileErrorException|CommandFailedException|null $error = null;

    public private(set) bool $finished = false;

    /**
     * @param Closure(): UpdateResult $work
     */
    public function run(Closure $work): void
    {
        try {
            $this->result = $work();
        } catch (MakefileErrorException|CommandFailedException $error) {
            $this->error = $error;
        } finally {
            $this->finished = true;
        }
    }

    public function waitsFor(self $other): bool
    {
        return $this === $other || ($this->waiting?->waitsFor($other) ?? false);
    }
}
