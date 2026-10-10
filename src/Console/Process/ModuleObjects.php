<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Override;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Engine\Expansion\LoadedObject\LoadedObject;
use Tamiroh\Phmake\Engine\IO\LoadableObjects;
use Tamiroh\Phmake\Engine\IO\LoadedObjectApi;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use WeakMap;

/**
 * Each object file has its own native host process, closed before rebuilding the file.
 */
final readonly class ModuleObjects implements LoadableObjects
{
    /** @var WeakMap<LoadedObject, ModuleHost> */
    private WeakMap $hosts;

    public function __construct(
        private string $executable,
        private Output $output,
    ) {
        $this->hosts = new WeakMap();
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MakefileErrorException
     */
    #[Override]
    public function call(LoadedObject $object, string $name, array $arguments, LoadedObjectApi $api): string
    {
        return $this->hosts[$object]->call($name, $arguments, $api);
    }

    /**
     * @throws MakefileErrorException
     */
    #[Override]
    public function load(LoadedObject $object, LoadedObjectApi $api): int
    {
        // Release the old process before creating its replacement.
        unset($this->hosts[$object]);
        $this->hosts[$object] = new ModuleHost($this->executable, $this->output);
        $source = [];
        preg_match('/^(.*):([0-9]+)$/D', $object->source ?? '', $source);
        return $this->hosts[$object]->load(
            str_starts_with($object->path, '/') ? $object->path : './' . $object->path,
            $object->setup,
            $source[1] ?? null,
            (int) ($source[2] ?? 0),
            $api,
        );
    }

    #[Override]
    public function unload(LoadedObject $object): void
    {
        unset($this->hosts[$object]);
    }
}
