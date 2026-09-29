<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * A dynamic object loaded by the load directive, or the Guile interpreter.
 */
interface DynamicObject
{
    /**
     * Call a function defined by the loaded object.
     *
     * @param list<string> $arguments
     *
     * @throws MakefileErrorException
     */
    public function call(string $name, array $arguments, LoadedObjectApi $api): string;

    /**
     * Evaluate a Guile expression.
     *
     * @throws MakefileErrorException
     */
    public function guile(string $expression, LoadedObjectApi $api): string;

    /**
     * Load the object and run its setup function, returning the setup function's result.
     *
     * @throws MakefileErrorException
     */
    public function load(string $path, string $setup, ?string $file, int $line, LoadedObjectApi $api): int;
}
