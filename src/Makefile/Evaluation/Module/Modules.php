<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation\Module;

use Closure;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\ModuleHost;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

final class Modules
{
    /** @var array<string, ModuleFunction> */
    public array $functions = [];

    /** @var array<string, LoadedModule> */
    public array $loaded = [];

    private ?LoadedModule $guileRuntime = null;

    /**
     * @param Closure(): ModuleHost|null $factory
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
        $module = $this->guileRuntime;
        if ($module === null) {
            $module = new LoadedModule('guile', '', $expander->source, false);
            $module->host = ($this->factory)();
            $this->guileRuntime = $module;
        }
        return $this->host($module)->guile($expression, new ExpansionRequests($this, $module, $expander));
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
        return $this->host($function->module)->call(
            $name,
            $arguments,
            new ExpansionRequests($this, $function->module, $expander),
        );
    }

    /**
     * @throws MakefileErrorException
     */
    public function load(string $name, bool $optional, VariableExpander $expander): LoadedModule
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
        $module = new LoadedModule(
            $name,
            $setup ?? (preg_replace('/[^A-Za-z0-9_]/', '_', explode('.', basename($name), 2)[0]) ?? '') . '_gmk_setup',
            $expander->source,
            $optional,
        );
        $this->loaded[$name] = $module;
        $this->initialize($module, $expander);
        return $module;
    }

    /**
     * @throws MakefileErrorException
     */
    public function reload(VariableExpander $expander): void
    {
        foreach ($this->loaded as $module) {
            if (
                $module->reload
                || $module->host === null && $expander->context->filesystem?->exists($module->path) === true
            ) {
                $this->initialize($module, $expander->atSource($module->source));
            }
        }
    }

    public function unload(string $name): void
    {
        $module = $this->loaded[$name] ?? null;
        if ($module !== null && !$module->keep) {
            $module->host = null;
            $module->reload = true;
        }
    }

    /**
     * @throws MakefileErrorException
     */
    private function host(LoadedModule $module): ModuleHost
    {
        return (
            $module->host ?? throw new MakefileErrorException("Module '{$module->path}' is not loaded", $module->source)
        );
    }

    /**
     * @throws MakefileErrorException
     */
    private function initialize(LoadedModule $module, VariableExpander $expander): void
    {
        if ($this->factory === null) {
            throw new MakefileErrorException('Native module loading is not available', $module->source);
        }
        if ($module->optional && !($expander->context->filesystem?->exists($module->path) ?? false)) {
            return;
        }
        $module->host = ($this->factory)();
        $source = [];
        preg_match('/^(.*):([0-9]+)$/D', $module->source ?? '', $source);
        $status = $this->host($module)->load(
            str_starts_with($module->path, '/') ? $module->path : './' . $module->path,
            $module->setup,
            $source[1] ?? null,
            (int) ($source[2] ?? 0),
            new ExpansionRequests($this, $module, $expander),
        );
        if ($status === 0) {
            throw new MakefileErrorException(
                "Failed to load symbol {$module->setup} from {$module->path}",
                $module->source,
            );
        }
        $module->keep = $status === -1;
        $module->reload = false;
        $names = [];
        foreach ($this->loaded as $loaded) {
            if ($loaded->host !== null) {
                $names[] = $loaded->path;
            }
        }
        $expander->context->variables['.LOADED'] = new Variable('.LOADED', implode(' ', $names), false, 'default');
    }
}
