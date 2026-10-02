<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Variable;

/**
 * A variable's expression, flavor, origin, and assignment modifiers.
 * Recursive expressions are expanded where the variable is used.
 */
final readonly class Variable
{
    public function __construct(
        public string $name,
        public string $expression,
        public bool $recursive = true,
        public string $origin = 'file',
        public ?string $source = null,
        public bool $private = false,
        public ?bool $export = null,
        public bool $append = false,
        public bool $conditional = false,
        public bool $environmentOverrides = false,
    ) {}

    public function promoteEnvironment(): self
    {
        if (!$this->environmentOverrides || $this->origin !== 'environment') {
            return $this;
        }
        return new self(
            $this->name,
            $this->expression,
            $this->recursive,
            'environment override',
            $this->source,
            $this->private,
            $this->export,
            $this->append,
            $this->conditional,
            true,
        );
    }

    /**
     * Let an environment variable override makefile assignments, as -e requests.
     */
    public function withEnvironmentOverrides(): self
    {
        if ($this->origin !== 'environment') {
            return $this;
        }
        return new self($this->name, $this->expression, $this->recursive, 'environment', environmentOverrides: true);
    }
}
