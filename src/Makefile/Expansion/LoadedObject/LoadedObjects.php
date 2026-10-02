<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Expansion\LoadedObject;

use Closure;
use Tamiroh\Phmake\Makefile\Expansion\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\DynamicObject;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable\Variable;

final class LoadedObjects
{
    /** @var array<string, LoadedFunction> */
    public array $functions = [];

    /** @var array<string, LoadedObject> */
    public array $loaded = [];

    private ?LoadedObject $guileRuntime = null;

    /**
     * @param Closure(): DynamicObject|null $factory
     */
    public function __construct(
        private readonly ?Closure $factory = null,
    ) {}

    /**
     * @throws MakefileErrorException
     */
    public function guile(string $expression, VariableExpander $expander): string
    {
        if ($this->factory === null) {
            throw new MakefileErrorException('Guile support is not available');
        }
        $object = $this->guileRuntime;
        if ($object === null) {
            $object = new LoadedObject('guile', '', $expander->source, false);
            $object->instance = ($this->factory)();
            $this->guileRuntime = $object;
        }
        return $this->instance($object)->guile($expression, new ExpansionApi($this, $object, $expander));
    }

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
        return $this->instance($function->object)->call(
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
                || $object->instance === null && $expander->context->filesystem?->exists($object->path) === true
            ) {
                $this->initialize($object, $expander->atSource($object->source));
            }
        }
    }

    public function unload(string $name): void
    {
        $object = $this->loaded[$name] ?? null;
        if ($object !== null && !$object->keep) {
            $object->instance = null;
            $object->reload = true;
        }
    }

    /**
     * @throws MakefileErrorException
     */
    private function initialize(LoadedObject $object, VariableExpander $expander): void
    {
        if ($this->factory === null) {
            throw new MakefileErrorException("The 'load' directive is not supported on this platform", $object->source);
        }
        if ($object->optional && !($expander->context->filesystem?->exists($object->path) ?? false)) {
            return;
        }
        $object->instance = ($this->factory)();
        $source = [];
        preg_match('/^(.*):([0-9]+)$/D', $object->source ?? '', $source);
        $status = $this->instance($object)->load(
            str_starts_with($object->path, '/') ? $object->path : './' . $object->path,
            $object->setup,
            $source[1] ?? null,
            (int) ($source[2] ?? 0),
            new ExpansionApi($this, $object, $expander),
        );
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
            if ($loaded->instance !== null) {
                $names[] = $loaded->path;
            }
        }
        $expander->context->variables['.LOADED'] = new Variable('.LOADED', implode(' ', $names), false, 'default');
    }

    /**
     * @throws MakefileErrorException
     */
    private function instance(LoadedObject $object): DynamicObject
    {
        return (
            $object->instance ?? throw new MakefileErrorException("'{$object->path}' is not loaded", $object->source)
        );
    }
}
