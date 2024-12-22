<?php

namespace Tamiroh\Phmake\Makefile;

interface ShellExecInterface
{
    public function exec(string $command): string;
}