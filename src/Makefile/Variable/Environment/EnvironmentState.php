<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Variable\Environment;

/**
 * @internal
 */
final class EnvironmentState
{
    public bool $expandingShell = false;

    /** @var array<string, string> */
    public array $inherited = [];
}
