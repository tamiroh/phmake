<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

interface Output
{
    public function write(string $text): void;

    public function writeInfo(string $message): void;

    public function writeLine(string $line): void;

    public function writeWarning(string $message, ?string $source = null): void;
}
