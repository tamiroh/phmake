<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_filter;
use function array_replace;
use function in_array;
use function str_replace;

/**
 * Target-local layers over live global definitions.
 */
final readonly class VariableScope
{
    /**
     * @param list<array<string, Variable>> $layers
     */
    public function __construct(
        public EvaluationContext $context,
        private array $layers = [],
    ) {}

    public function inherit(): self
    {
        $layers = [];
        foreach ($this->layers as $layer) {
            $layers[] = array_filter($layer, static fn(Variable $variable): bool => !$variable->private);
        }
        return new self($this->context, $layers);
    }

    public function variable(string $name): ?Variable
    {
        $global = $this->context->variables[$name] ?? null;
        $value = $global->private ?? false ? null : $global;
        foreach ($this->layers as $layer) {
            $local = $layer[$name] ?? null;
            if ($local === null || $local->conditional && $value !== null) {
                continue;
            }
            if (
                $global !== null
                && $local->origin !== 'override'
                && in_array($global->origin, ['command line', 'environment override'], true)
            ) {
                $local = $global;
            }
            $expression = $local->expression;
            if ($local->append && $value !== null && $value->expression !== '') {
                $expression =
                    (
                        $value->recursive || !$local->recursive
                            ? $value->expression
                            : str_replace('$', '$$', $value->expression)
                    ) . ($expression === '' ? '' : ' ' . $expression);
            }
            $value = new Variable(
                $local->name,
                $expression,
                $local->recursive,
                $local->origin,
                $local->source,
                $local->private,
                $local->export ?? $value?->export,
            );
        }
        return $value;
    }

    /**
     * @return list<Variable>
     */
    public function variables(): array
    {
        $names = $this->context->variables;
        foreach ($this->layers as $layer) {
            $names = array_replace($names, $layer);
        }
        $result = [];
        foreach ($names as $definition) {
            $variable = $this->variable($definition->name);
            if ($variable !== null) {
                $result[] = $variable;
            }
        }
        return $result;
    }

    /**
     * @param array<string, Variable> $variables
     */
    public function with(array $variables): self
    {
        return new self($this->context, [...$this->layers, $variables]);
    }
}
