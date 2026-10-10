<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Expansion;

use Closure;

/**
 * Reading makefile syntax, initially and later through eval or loaded-object callbacks.
 * Once initial reading ends, eval may still change variables but cannot define prerequisites.
 * This state is shared through remaking and ordinary goals; a restart creates a new one.
 */
final class MakefileReading
{
    public bool $initial = true;

    /** Whether .POSIX has been read. */
    public bool $posix = false;

    /**
     * Read expanded text as makefile syntax in the calling expansion.
     *
     * @var Closure(string, VariableExpander): void|null
     */
    public ?Closure $evaluate = null;
}
