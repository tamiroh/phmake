<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

interface Shell
{
    /**
     * @param array<string, string|false> $environment
     * @throws MakefileErrorException
     */
    public function exec(string $command, array $environment = []): int;
}
