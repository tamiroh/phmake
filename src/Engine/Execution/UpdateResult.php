<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution;

use Tamiroh\Phmake\Engine\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Engine\MakefileErrorException;

/**
 * @internal
 */
final readonly class UpdateResult
{
    public function __construct(
        public bool $changed = false,
        public MakefileErrorException|CommandFailedException|null $failure = null,
        public bool $blocked = false,
        public bool $circular = false,
    ) {}
}
