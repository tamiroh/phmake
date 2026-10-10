<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Expansion\LoadedObject;

use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\IO\LoadableObjects;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Variable\Variable;

final class LoadedObjects
{
    /** @var array<string, LoadedFunction> */
    public array $functions = [];

    /** @var array<string, LoadedObject> */
    public array $loaded = [];

    public function __construct(
        private readonly ?LoadableObjects $objects = null,
    ) {}

    /**
     * @param list<string> $arguments
     * @param list<string> $expanding
     *
     * @throws MakefileErrorException
     */
    public function invoke(
        string $name,
        array $arguments,
        VariableExpander $expander,
        array $expanding,
        bool $expanded,
    ): ?string {
        $function = $this->functions[$name] ?? null;
        if ($function === null) {
            return null;
        }
        if (count($arguments) < $function->minimum) {
            throw new MakefileErrorException(
                'insufficient number of arguments (' . count($arguments) . ") to function '{$name}'",
            );
        }
        if ($function->maximum !== 0) {
            $arguments = array_slice($arguments, 0, $function->maximum);
        }
        if ($function->expand && !$expanded) {
            foreach ($arguments as &$argument) {
                $argument = $expander->expand($argument, $expanding);
            }
            unset($argument);
        }
        if (!$function->object->loaded || $this->objects === null) {
            throw new MakefileErrorException("'{$function->object->path}' is not loaded", $function->object->source);
        }
        return $this->objects->call(
            $function->object,
            $name,
            $arguments,
            new ExpansionApi($this, $function->object, $expander),
        );
    }

    /**
     * @throws MakefileErrorException
     */
    public function load(string $name, bool $optional, VariableExpander $expander): LoadedObject
    {
        $setup = null;
        if (preg_match('/^(.*)\(([^()]*)\)$/D', $name, $matches) === 1) {
            $name = $matches[1];
            $setup = $matches[2];
        }
        $name = preg_replace('~^(?:\./)+~', '', $name) ?? $name;
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }
        $object = new LoadedObject(
            $name,
            $setup ?? (preg_replace('/[^A-Za-z0-9_]/', '_', explode('.', basename($name), 2)[0]) ?? '') . '_gmk_setup',
            $expander->source,
            $optional,
        );
        $this->loaded[$name] = $object;
        $this->initialize($object, $expander);
        return $object;
    }

    /**
     * @throws MakefileErrorException
     */
    public function reload(VariableExpander $expander): void
    {
        foreach ($this->loaded as $object) {
            if (
                $object->reload
                || !$object->loaded && $expander->context->filesystem?->exists($object->path) === true
            ) {
                $this->initialize($object, $expander->atSource($object->source));
            }
        }
    }

    public function unload(string $name): void
    {
        $object = $this->loaded[$name] ?? null;
        if ($object !== null && !$object->keep) {
            $this->objects?->unload($object);
            $object->loaded = false;
            $object->reload = true;
        }
    }

    /**
     * @throws MakefileErrorException
     */
    private function initialize(LoadedObject $object, VariableExpander $expander): void
    {
        if ($this->objects === null) {
            throw new MakefileErrorException("The 'load' directive is not supported on this platform", $object->source);
        }
        if ($object->optional && !($expander->context->filesystem?->exists($object->path) ?? false)) {
            return;
        }
        // Setup may register a function and immediately call it through gmk_expand.
        $object->loaded = true;
        $status = $this->objects->load($object, new ExpansionApi($this, $object, $expander));
        if ($status === 0) {
            throw new MakefileErrorException(
                "Failed to load symbol {$object->setup} from {$object->path}",
                $object->source,
            );
        }
        $object->keep = $status === -1;
        $object->reload = false;
        $names = [];
        foreach ($this->loaded as $loaded) {
            if ($loaded->loaded) {
                $names[] = $loaded->path;
            }
        }
        $expander->context->variables['.LOADED'] = new Variable('.LOADED', implode(' ', $names), false, 'default');
    }
}
