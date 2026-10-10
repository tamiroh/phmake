<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\IO;

use Tamiroh\Phmake\Engine\MakefileErrorException;

/**
 * Shell expansion (including != assignments) and recipe execution using SHELL,
 * .SHELLFLAGS and exported variables. Process creation stays behind this contract.
 */
interface Shell
{
    /**
     * @param array<string, string|false> $environment
     *
     * @throws MakefileErrorException
     *
     * @return array{output: string, status: int}
     */
    public function capture(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
    ): array;

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
