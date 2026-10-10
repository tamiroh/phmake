<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Evaluation;

use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Variable\Variable;

/**
 * Invocation settings which can also be changed by assignments to MAKEFLAGS.
 */
interface Configuration
{
    public bool $noBuiltinRules { get; }

    /** @var list<string> */
    public array $includeDirectories { get; }

    /**
     * @param array<string, Variable> $variables
     *
     * @throws MakefileErrorException
     */
    public function finishReading(array &$variables, VariableExpander $expander): void;

    /**
     * @param array<string, Variable> $variables
     *
     * @throws MakefileErrorException
     */
    public function updateMakeflags(
        array &$variables,
        ?VariableExpander $expander = null,
        string $origin = 'file',
    ): void;
}
