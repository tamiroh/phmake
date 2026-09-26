<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

use Closure;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

interface ModuleHost
{
    /**
     * @param list<string> $arguments
     * @param Closure(string, list<string>): ?string $callback
     *
     * @throws MakefileErrorException
     */
    public function request(string $operation, array $arguments, Closure $callback): string;
}
