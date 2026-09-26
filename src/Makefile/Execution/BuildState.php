<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Reporting\Diagnostics;

final class BuildState
{
    /** @var array<string, UpdateResult> */
    public array $results = [];

    /** @var array<string, UpdateResult> */
    public array $recipes = [];

    /** @var array<string, true> */
    public array $simulated = [];

    /** @var array<string, array<string, true>> */
    public array $dropped = [];

    public bool $remaking = false;

    public bool $needsUpdate = false;

    public bool $failed = false;

    public bool $waiting = false;

    public function __construct(
        public readonly int $restarts = 0,
    ) {}

    public function failure(MakefileErrorException|CommandFailedException $error, Output $output): UpdateResult
    {
        if (!$this->remaking) {
            Diagnostics::report($error, $output, false);
        }
        return new UpdateResult(failure: $error);
    }
}
