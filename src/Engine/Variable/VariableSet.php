<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Variable;

/**
 * Shared bindings: declarations can change attributes without replacing their values.
 */
final class VariableSet
{
    /**
     * @param array<string, Variable> $definitions
     */
    public function __construct(
        public array $definitions = [],
    ) {}
}
