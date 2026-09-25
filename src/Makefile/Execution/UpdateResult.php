<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

final readonly class UpdateResult
{
    public function __construct(
        public bool $changed = false,
        public MakefileErrorException|CommandFailedException|null $failure = null,
        public bool $blocked = false,
    ) {}
}
