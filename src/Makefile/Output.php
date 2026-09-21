<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

interface Output
{
    public function write(string $text): void;

    public function writeInfo(string $message): void;

    public function writeWarning(string $message): void;

    public function writeLine(string $line): void;
}
