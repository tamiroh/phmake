<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

final class MissingTargetException extends MakefileErrorException
{
    public function __construct(string $target, ?string $neededBy = null)
    {
        parent::__construct(
            "No rule to make target '{$target}'" . ($neededBy === null ? '' : ", needed by '{$neededBy}'"),
        );
    }
}
