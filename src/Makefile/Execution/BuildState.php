<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function spl_object_id;

final class BuildState
{
    /** @var array<string, UpdateResult> */
    public array $results = [];

    /** @var array<string, true> */
    public array $visiting = [];

    /** @var array<string, UpdateResult> */
    public array $recipes = [];

    /** @var array<string, true> */
    public array $simulated = [];

    /** @var array<int, true> */
    private array $reported = [];

    public bool $remaking = false;

    public bool $needsUpdate = false;

    public bool $failed = false;

    public function __construct(
        public readonly int $restarts = 0,
    ) {}

    public function failure(MakefileErrorException|CommandFailedException $error, Output $output): UpdateResult
    {
        if (!$this->remaking && !isset($this->reported[spl_object_id($error)])) {
            if (!($error instanceof CommandFailedException && $error->reported)) {
                $output->writeWarning(
                    '*** ' . $error->getMessage() . ($error instanceof CommandFailedException ? '' : '.'),
                    $error instanceof MakefileErrorException ? $error->source : null,
                );
            }
            $this->reported[spl_object_id($error)] = true;
        }
        return new UpdateResult(failure: $error);
    }
}
