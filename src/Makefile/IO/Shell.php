<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

interface Shell
{
    /**
     * @param array<string, string|false> $environment
     *
     * @throws MakefileErrorException
     */
    public function capture(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
    ): ShellResult;

    /**
     * @param array<string, string|false> $environment
     *
     * @throws MakefileErrorException
     */
    public function exec(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
        bool $ignoreErrors = false,
        bool $recursive = false,
    ): int;
}
