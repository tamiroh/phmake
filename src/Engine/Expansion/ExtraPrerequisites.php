<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Expansion;

use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\DependencySyntax;
use Tamiroh\Phmake\Engine\Rule\Prerequisites;
use Tamiroh\Phmake\Engine\Variable\Variable;

use function in_array;

/**
 * Dependency-only prerequisites use a target's own definition, without inheritance.
 *
 * @internal
 */
final class ExtraPrerequisites
{
    private readonly Prerequisites $global;

    /** @var array<string, Prerequisites> */
    private array $local = [];

    /**
     * Capture extra dependencies before any recipes run, without target inheritance.
     *
     * @throws MakefileErrorException
     */
    public function __construct(Makefile $makefile, EvaluationContext $context, Filesystem $filesystem, Output $output)
    {
        $expander = new VariableExpander($context, $output);
        $this->global = self::expand($context->variables['.EXTRA_PREREQS'] ?? null, $expander, $filesystem);
        foreach ($makefile->targetVariables->mentioned() as $name) {
            $definitions = $makefile->targetVariables->definitionsFor($name);
            $this->local[$name] = self::expand($definitions['.EXTRA_PREREQS'] ?? null, $expander, $filesystem);
        }
    }

    /**
     * @throws MakefileErrorException
     */
    private static function expand(
        ?Variable $variable,
        VariableExpander $expander,
        Filesystem $filesystem,
    ): Prerequisites {
        if ($variable === null) {
            return new Prerequisites();
        }
        $prerequisites = DependencySyntax::parse($expander->expand($variable->expression), $filesystem);
        return new Prerequisites(
            sequence: $prerequisites->sequence,
            extra: $prerequisites->normal,
            extraOrderOnly: $prerequisites->orderOnly,
        );
    }

    public function forTarget(string $name): Prerequisites
    {
        $prerequisites = $this->local[$name] ?? $this->global;
        return in_array($name, $prerequisites->sequence, true) ? new Prerequisites() : $prerequisites;
    }
}
