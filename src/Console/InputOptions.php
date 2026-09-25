<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

/**
 * Paths selected by command-line and inherited make flags.
 */
final class InputOptions
{
    /** @var list<string> */
    public array $makefiles = [];

    /** @var list<string> */
    public array $directories = [];

    /** @var list<string> */
    public array $includes = [];

    /** @var list<string> */
    public array $evaluations = [];
}
