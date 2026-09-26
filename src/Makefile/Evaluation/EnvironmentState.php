<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

final class EnvironmentState
{
    public bool $expandingShell = false;

    /** @var array<string, string> */
    public array $inherited = [];
}
