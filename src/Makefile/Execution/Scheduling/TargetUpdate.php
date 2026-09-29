<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Scheduling;

use Closure;
use Fiber;
use LogicException;
use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\Execution\UpdateResult;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Throwable;
use WeakMap;

/**
 * The update of one target and its prerequisites, which can wait while other updates proceed.
 *
 * @internal
 */
final class TargetUpdate
{
    /** @var WeakMap<Fiber<null, Closure(): bool, null, void>, self>|null */
    private static ?WeakMap $updates = null;

    /** @var Fiber<null, Closure(): bool, null, void> */
    private readonly Fiber $fiber;

    /** @var (Closure(): bool)|null */
    public ?Closure $ready = null;

    public ?UpdateResult $result = null;

    public ?self $waiting = null;

    public MakefileErrorException|CommandFailedException|null $error = null;

    /**
     * @param Closure(): UpdateResult $work
     */
    public function __construct(
        public readonly string $name,
        Closure $work,
    ) {
        $this->fiber = new Fiber(function () use ($work): void {
            try {
                $this->result = $work();
            } catch (MakefileErrorException|CommandFailedException $error) {
                $this->error = $error;
            }
        });
        self::$updates ??= new WeakMap();
        self::$updates[$this->fiber] = $this;
    }

    /**
     * The target update that is running now, or null outside of any.
     */
    public static function current(): ?self
    {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? null : self::$updates[$fiber] ?? null;
    }

    public function finished(): bool
    {
        return $this->fiber->isTerminated();
    }

    public function proceed(): void
    {
        try {
            $this->ready = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
        } catch (Throwable $error) {
            throw new LogicException('Unexpected failure in target update', previous: $error);
        }
    }

    public function waitsFor(self $other): bool
    {
        return $this === $other || ($this->waiting?->waitsFor($other) ?? false);
    }
}
