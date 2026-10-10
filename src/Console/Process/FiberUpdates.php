<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Closure;
use Fiber;
use LogicException;
use Override;
use Tamiroh\Phmake\Console\Process\Internal\Waiting;
use Tamiroh\Phmake\Engine\Execution\Scheduling\TargetUpdate;
use Tamiroh\Phmake\Engine\Execution\UpdateResult;
use Tamiroh\Phmake\Engine\IO\TargetUpdates;
use Throwable;
use WeakMap;

/**
 * Cooperatively run target updates on PHP fibers, also used by recipe processes and output buffers.
 */
final class FiberUpdates implements TargetUpdates
{
    /** @var WeakMap<Fiber<null, Closure(): bool, null, void>, TargetUpdate>|null */
    private static ?WeakMap $current = null;

    /** @var WeakMap<TargetUpdate, Fiber<null, Closure(): bool, null, void>> */
    private readonly WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    /**
     * @param Closure(): UpdateResult $work
     */
    #[Override]
    public function create(Closure $work): TargetUpdate
    {
        $update = new TargetUpdate();
        /** @var Fiber<null, Closure(): bool, null, void> $fiber */
        $fiber = new Fiber(static function () use ($update, $work): void {
            $update->run($work);
        });
        $this->fibers[$update] = $fiber;
        self::$current ??= new WeakMap();
        self::$current[$fiber] = $update;
        return $update;
    }

    #[Override]
    public function current(): ?TargetUpdate
    {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? null : self::$current[$fiber] ?? null;
    }

    #[Override]
    public function proceed(TargetUpdate $update): void
    {
        $fiber = $this->fibers[$update];
        try {
            $update->ready = $fiber->isStarted() ? $fiber->resume() : $fiber->start();
        } catch (Throwable $error) {
            throw new LogicException('Unexpected failure in target update', previous: $error);
        } finally {
            if ($update->finished) {
                unset($this->fibers[$update], self::$current[$fiber]);
            }
        }
    }

    /**
     * @param Closure(): bool $ready
     */
    #[Override]
    public function waitUntil(Closure $ready): void
    {
        Waiting::until($ready);
    }
}
