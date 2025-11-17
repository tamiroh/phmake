<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

interface Output
{
    public function write(string $text): void;

    public function writeLine(string $line): void;
}
