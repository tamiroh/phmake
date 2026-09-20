<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use RuntimeException;

final class ParseException extends RuntimeException
{
    public function __construct(
        public readonly int $lineNumber,
        string $message,
    ) {
        parent::__construct("Makefile:$lineNumber: $message");
    }
}
