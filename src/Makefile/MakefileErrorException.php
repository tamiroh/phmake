<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Exception;

class MakefileErrorException extends Exception
{
    public bool $reported = false;

    public function __construct(
        string $message,
        public readonly ?string $source = null,
    ) {
        parent::__construct($message);
    }
}
