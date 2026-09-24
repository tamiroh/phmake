<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;

/** Invocation settings which can also be changed by assignments to MAKEFLAGS. */
interface Configuration
{
    public bool $noBuiltinRules { get; }

    /**
     * @param array<string, Variable> $variables
     * @throws MakefileErrorException
     */
    public function updateMakeflags(array &$variables): void;
}
