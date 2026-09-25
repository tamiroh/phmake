<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

final class ExecutionOptions
{
    public bool $dryRun = false;

    public bool $question = false;

    public bool $touch = false;

    public bool $alwaysMake = false;

    public bool $keepGoing = false;

    public bool $ignoreErrors = false;

    public bool $silent = false;

    /** @var list<string> */
    public array $oldFiles = [];

    /** @var list<string> */
    public array $newFiles = [];

    public function forMakefiles(int $restarts): self
    {
        $options = clone $this;
        $options->dryRun = false;
        $options->question = false;
        $options->touch = false;
        if ($restarts > 0) {
            $options->alwaysMake = false;
            $options->newFiles = [];
        }
        return $options;
    }
}
