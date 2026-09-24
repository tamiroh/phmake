<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Closure;

/** Mutable global definitions shared by parsing, expansion, and recipe execution. */
final class EvaluationContext
{
    /** @var array<string, Variable> */
    public array $variables = [];

    /** @var null|Closure(string, VariableExpander): void */
    public ?Closure $evaluate = null;

    public bool $reading = true;

    /** @param list<Variable> $variables */
    public function __construct(array $variables = [])
    {
        foreach ($variables as $variable) {
            $this->variables[$variable->name] = $variable;
        }
    }
}
