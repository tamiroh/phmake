<?php

namespace Tamiroh\Phmake\Makefile;

interface Shell
{
    public function exec(string $command): string;
}