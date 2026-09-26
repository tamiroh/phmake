<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Scheduling;

use Closure;
use Fiber;
use LogicException;
use Throwable;

/**
 * @internal
 */
final class Suspension
{
    /**
     * @param Closure(): bool $ready
     */
    public static function until(Closure $ready): void
    {
        try {
            Fiber::suspend($ready);
        } catch (Throwable $error) {
            // The scheduler resumes fibers normally; it never injects exceptions.
            throw new LogicException('Unexpected exception injected into a build task', previous: $error);
        }
    }
}
