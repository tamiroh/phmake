<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation\Environment;

/**
 * @internal
 */
final class EnvironmentState
{
    public bool $expandingShell = false;

    /** @var array<string, string> */
    public array $inherited = [];
}
