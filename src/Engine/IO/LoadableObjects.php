<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\IO;

use Tamiroh\Phmake\Engine\Expansion\LoadedObject\LoadedObject;
use Tamiroh\Phmake\Engine\MakefileErrorException;

/**
 * The load directive's object files and their registered functions.
 */
interface LoadableObjects
{
    /**
     * @param list<string> $arguments
     *
     * @throws MakefileErrorException
     */
    public function call(LoadedObject $object, string $name, array $arguments, LoadedObjectApi $api): string;

    /**
     * Load an object and return its setup function's result.
     *
     * @throws MakefileErrorException
     */
    public function load(LoadedObject $object, LoadedObjectApi $api): int;

    public function unload(LoadedObject $object): void;
}
