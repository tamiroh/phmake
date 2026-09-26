<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Exception;

final class ParseException extends Exception
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $reason,
    ) {
        parent::__construct("Makefile:$lineNumber: $reason");
    }
}
