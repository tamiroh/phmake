<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Variable;

use Closure;
use Tamiroh\Phmake\Engine\MakefileErrorException;

/**
 * A stored definition and the parent value consulted only when expanding an append.
 */
final readonly class VariableBinding
{
    public function __construct(
        public Variable $definition,
        public ?self $inherited = null,
    ) {}

    /**
     * @param Closure(Variable): string $expand
     *
     * @throws MakefileErrorException
     */
    public function expand(Closure $expand): string
    {
        $before = $this->inherited?->expand($expand) ?? '';
        $value = $this->definition->recursive ? $expand($this->definition) : $this->definition->expression;
        return $before === '' ? $value : ($value === '' ? $before : $before . ' ' . $value);
    }
}
