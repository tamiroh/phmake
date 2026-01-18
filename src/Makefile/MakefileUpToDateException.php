<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Exception;

class MakefileUpToDateException extends Exception
{
    public function __construct(
        public readonly string $target,
    ) {
        parent::__construct();
    }
}
