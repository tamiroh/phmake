<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution;

use Tamiroh\Phmake\Engine\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Reporting\Diagnostics;

/**
 * @internal
 */
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
