<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Recipe;

use Exception;

final class CommandFailedException extends Exception
{
    public bool $reported = false;

    public function __construct(
        public readonly string $target,
        public readonly int $exitCode,
        ?string $source = null,
    ) {
        parent::__construct('[' . ($source === null ? '' : $source . ': ') . "{$target}] Error {$exitCode}");
    }
}
