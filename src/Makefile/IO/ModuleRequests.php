<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * What a loaded object may ask make to do while it runs.
 */
interface ModuleRequests
{
    /**
     * Define a function that makefiles can call (gmk_add_function).
     *
     * @throws MakefileErrorException
     */
    public function define(string $name, int $minimum, int $maximum, bool $expand): void;

    /**
     * Evaluate text as makefile syntax (gmk_eval).
     *
     * @throws MakefileErrorException
     */
    public function eval(string $text, ?string $file, int $line): void;

    /**
     * Expand text in the current context (gmk_expand).
     *
     * @throws MakefileErrorException
     */
    public function expand(string $text): string;
}
