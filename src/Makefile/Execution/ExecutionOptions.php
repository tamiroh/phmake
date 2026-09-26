<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

final class ExecutionOptions
{
    public ParallelOptions $parallel;

    public ReportingOptions $reporting;

    public function __construct()
    {
        $this->parallel = new ParallelOptions();
        $this->reporting = new ReportingOptions();
    }

    public function __clone(): void
    {
        $this->parallel = clone $this->parallel;
        $this->reporting = clone $this->reporting;
    }

    public bool $dryRun = false;

    public bool $question = false;

    public bool $touch = false;

    public bool $alwaysMake = false;

    public bool $keepGoing = false;

    public bool $ignoreErrors = false;

    /** @var list<string> */
    public array $oldFiles = [];

    /** @var list<string> */
    public array $newFiles = [];

    public function forMakefiles(int $restarts): self
    {
        $options = clone $this;
        $options->reporting->remaking = true;
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
