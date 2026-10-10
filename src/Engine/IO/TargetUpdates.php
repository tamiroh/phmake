<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\IO;

use Closure;
use Tamiroh\Phmake\Engine\Execution\Scheduling\TargetUpdate;
use Tamiroh\Phmake\Engine\Execution\UpdateResult;

/**
 * Target updates that can wait while other targets make progress.
 */
interface TargetUpdates
{
    /**
     * @param Closure(): UpdateResult $work
     */
    public function create(Closure $work): TargetUpdate;

    public function current(): ?TargetUpdate;

    public function proceed(TargetUpdate $update): void;

    /**
     * @param Closure(): bool $ready
     */
    public function waitUntil(Closure $ready): void;
}
