<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Invocation;

use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Evaluation\Assignment;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function array_map;

/**
 * Options which change how makefiles are read and are passed to sub-makes through MAKEFLAGS.
 */
final class InvocationOptions
{
    public ReversibleOptions $switches;

    public bool $noBuiltinRules = false;

    public bool $noBuiltinVariables = false;

    public bool $environmentOverrides = false;

    /** @var list<string> */
    public array $includes = [];

    /** @var list<string> */
    public array $evaluations = [];

    public function __construct()
    {
        $this->switches = new ReversibleOptions();
    }

    /**
     * Apply -R and -r again once makefiles may have changed them through MAKEFLAGS.
     *
     * @param array<string, Variable> $variables
     *
     * @throws MakefileErrorException
     */
    public function afterReading(array &$variables): void
    {
        $this->noBuiltinRules = $this->noBuiltinRules || $this->noBuiltinVariables;
        if ($this->noBuiltinVariables) {
            $variables = Builtins::withoutVariables($variables);
        }
        if ($this->noBuiltinRules) {
            new Assignment('SUFFIXES', ':=', '')->apply($variables, 'default');
        }
    }

    /**
     * Apply -R, -e, and -r to the variables defined before any makefile is read.
     *
     * @param array<string, Variable> $variables
     *
     * @return array<string, Variable>
     */
    public function beforeReading(array $variables): array
    {
        if ($this->noBuiltinVariables) {
            $variables = Builtins::withoutVariables($variables);
        }
        $variables = $this->overrideEnvironment($variables);
        if ($this->noBuiltinRules && ($variables['SUFFIXES']->origin ?? '') === 'default') {
            $variables['SUFFIXES'] = new Variable('SUFFIXES', '', false, 'default');
        }
        return $variables;
    }

    /**
     * @param array<string, Variable> $variables
     *
     * @return array<string, Variable>
     */
    public function overrideEnvironment(array $variables): array
    {
        return $this->environmentOverrides
            ? array_map(static fn(Variable $variable): Variable => $variable->withEnvironmentOverrides(), $variables)
            : $variables;
    }

    public function __clone(): void
    {
        $this->switches = clone $this->switches;
    }
}
