<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

/**
 * Timestamp overrides and symbolic-link policy for one invocation.
 */
final class FileOptions
{
    /** @var list<string> */
    public array $oldFiles = [];

    /** @var list<string> */
    public array $newFiles = [];

    public bool $checkSymlinkTimes = false;
}
