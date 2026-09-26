<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Scheduling;

final class ParallelOptions
{
    /** Zero means an unlimited number of jobs. */
    public int $jobs = 1;

    public ?string $auth = null;

    public string $sync = 'none';

    public bool $syncSpecified = false;

    public ?string $mutex = null;

    public ?float $load = null;

    public ?string $shuffle = null;

    public string $style = 'fifo';

    public bool $reset = false;

    public ?int $commandJobs = null;
}
