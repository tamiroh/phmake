<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Formatter;

use Tamiroh\Phmake\Parser\Ast\MakefileNode;

/**
 * Conservative first pass: preserve all whitespace until a rewrite is proven safe.
 */
final readonly class Formatter
{
    public function format(MakefileNode $makefile): string
    {
        return $makefile->source();
    }
}
