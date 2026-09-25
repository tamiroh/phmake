<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Closure;

use function in_array;

/**
 * Mutable global definitions shared by parsing, expansion, and recipe execution.
 */
final class EvaluationContext
{
    /** @var array<string, Variable> */
    public array $variables = [];

    /** @var Closure(string, VariableExpander): void|null */
    public ?Closure $evaluate = null;

    public bool $reading = true;

    public ?Shell $shell = null;

    public ?Filesystem $filesystem = null;

    public Exports $exports;

    public bool $shellEnvironment = false;

    /** @var array<string, string> */
    public array $inheritedEnvironment = [];

    /**
     * @param list<Variable> $variables
     */
    public function __construct(array $variables = [])
    {
        $this->exports = new Exports();
        foreach ($variables as $variable) {
            $this->variables[$variable->name] = $variable;
            if (in_array($variable->origin, ['environment', 'environment override'], true)) {
                $this->inheritedEnvironment[$variable->name] = $variable->expression;
            }
        }
    }
}
