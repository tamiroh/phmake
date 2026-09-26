<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\Execution\Files\FileOptions;
use Tamiroh\Phmake\Makefile\Execution\Scheduling\ParallelOptions;
use Tamiroh\Phmake\Makefile\Reporting\ReportingOptions;

final class ExecutionOptions
{
    public ParallelOptions $parallel;

    public ReportingOptions $reporting;

    public FileOptions $files;

    public function __construct()
    {
        $this->parallel = new ParallelOptions();
        $this->reporting = new ReportingOptions();
        $this->files = new FileOptions();
    }

    public function __clone(): void
    {
        $this->parallel = clone $this->parallel;
        $this->reporting = clone $this->reporting;
        $this->files = clone $this->files;
    }

    public bool $dryRun = false;

    public bool $question = false;

    public bool $touch = false;

    public bool $alwaysMake = false;

    public bool $keepGoing = false;

    public bool $ignoreErrors = false;

    public function forMakefiles(int $restarts): self
    {
        $options = clone $this;
        $options->reporting->remaking = true;
        $options->dryRun = false;
        $options->question = false;
        $options->touch = false;
        if ($restarts > 0) {
            $options->alwaysMake = false;
            $options->files->newFiles = [];
        }
        return $options;
    }
}
