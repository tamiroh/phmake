<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Variable;

use Tamiroh\Phmake\Engine\Expansion\EvaluationContext;

use function array_replace;
use function array_reverse;
use function count;

/**
 * Target-local layers over live global definitions.
 */
final readonly class VariableScope
{
    /**
     * @param list<array{VariableSet, bool}> $layers
     */
    public function __construct(
        public EvaluationContext $context,
        private array $layers = [],
    ) {}

    public function binding(string $name, bool $environment = false): ?VariableBinding
    {
        $global = $this->context->variables[$name] ?? null;
        $binding = $global === null || $global->private ? null : new VariableBinding($global);
        $last = count($this->layers) - 1;
        foreach ($this->layers as $index => [$set, $inherited]) {
            $local = $set->definitions[$name] ?? null;
            if ($local === null || $local->private && ($inherited || $environment && $index !== $last)) {
                continue;
            }
            $definition = $local->withAttributes($local->private, $local->export ?? $binding?->definition->export);
            $binding = new VariableBinding($definition, $local->append ? $binding : null);
        }
        return $binding;
    }

    public function inherit(): self
    {
        $layers = [];
        foreach ($this->layers as [$set]) {
            $layers[] = [$set, true];
        }
        return new self($this->context, $layers);
    }

    public function setAttributes(string $name, bool $private, ?bool $export): bool
    {
        foreach (array_reverse($this->layers) as [$set, $inherited]) {
            $variable = $set->definitions[$name] ?? null;
            if ($variable !== null && !($inherited && $variable->private)) {
                $set->definitions[$name] = $variable->withAttributes($private, $export ?? $variable->export);
                return true;
            }
        }
        $global = $this->context->variables[$name] ?? null;
        if ($global === null || $global->private) {
            return false;
        }
        $this->context->variables[$name] = $global->withAttributes($private, $export ?? $global->export);
        return true;
    }

    public function variable(string $name): ?Variable
    {
        return $this->binding($name)?->definition;
    }

    /**
     * @return list<Variable>
     */
    public function variables(bool $environment = false): array
    {
        $names = $this->context->variables;
        foreach ($this->layers as [$set]) {
            $names = array_replace($names, $set->definitions);
        }
        $result = [];
        foreach ($names as $definition) {
            $variable = $this->binding($definition->name, $environment)?->definition;
            if ($variable !== null) {
                $result[] = $variable;
            }
        }
        return $result;
    }

    public function withSet(VariableSet $set): self
    {
        return new self($this->context, [...$this->layers, [$set, false]]);
    }
}
