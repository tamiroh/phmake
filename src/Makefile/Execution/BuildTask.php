<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Closure;
use Fiber;
use LogicException;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Throwable;

final class BuildTask
{
    /** @var Fiber<null, Closure(): bool, null, void> */
    public readonly Fiber $fiber;

    /** @var (Closure(): bool)|null */
    public ?Closure $ready = null;

    public ?UpdateResult $result = null;

    public ?self $waiting = null;

    public function waitsFor(self $other): bool
    {
        return $this === $other || ($this->waiting?->waitsFor($other) ?? false);
    }

    public MakefileErrorException|CommandFailedException|null $error = null;

    /**
     * @param Closure(): UpdateResult $work
     */
    public function __construct(Closure $work)
    {
        $this->fiber = new Fiber(function () use ($work): void {
            try {
                $this->result = $work();
            } catch (MakefileErrorException|CommandFailedException $error) {
                $this->error = $error;
            }
        });
    }

    public function advance(): void
    {
        try {
            $this->ready = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
        } catch (Throwable $error) {
            throw new LogicException('Unexpected failure in build task', previous: $error);
        }
    }
}
