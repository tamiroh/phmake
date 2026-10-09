<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Formatter;

use Tamiroh\Phmake\Parser\Ast\MakefileNode;

final readonly class Formatter
{
    public function format(MakefileNode $makefile): string
    {
        return new Printer()->print($makefile);
    }
}
