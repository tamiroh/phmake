<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

interface Shell
{
    public function exec(string $command): string;
}
