<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

/**
 * Make's messages: info / warning functions, diagnostics, traces and recipe echoing.
 * Stream selection, buffering and output synchronization belong to the implementation.
 */
interface Output
{
    public function write(string $text): void;

    public function writeInfo(string $message): void;

    public function writeLine(string $line): void;

    public function writeWarning(string $message, ?string $source = null): void;
}
