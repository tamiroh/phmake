<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Exception;

final class CommandFailedException extends Exception
{
    public function __construct(
        public readonly string $target,
        public readonly int $exitCode,
    ) {
        parent::__construct("[$target] Error $exitCode");
    }
}
