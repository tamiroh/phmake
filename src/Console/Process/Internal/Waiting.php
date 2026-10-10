<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process\Internal;

use Closure;
use Fiber;
use LogicException;
use Throwable;

/**
 * @internal
 */
final class Waiting
{
    /**
     * Let other target updates proceed until the condition holds.
     *
     * @param Closure(): bool $ready
     */
    public static function until(Closure $ready): void
    {
        try {
            Fiber::suspend($ready);
        } catch (Throwable $error) {
            // Jobs resumes target updates normally; it never injects exceptions.
            throw new LogicException('Unexpected exception injected into a target update', previous: $error);
        }
    }
}
