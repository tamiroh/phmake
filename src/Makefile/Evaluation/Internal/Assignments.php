<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation\Internal;

use Tamiroh\Phmake\Makefile\Evaluation\Configuration;
use Tamiroh\Phmake\Makefile\Evaluation\MakefileSources;
use Tamiroh\Phmake\Makefile\Expansion\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable\Assignment;
use Tamiroh\Phmake\Makefile\Variable\Environment\Exports;
use Tamiroh\Phmake\Makefile\Variable\Variable;
use Tamiroh\Phmake\Parser\Ast;

use function implode;

final readonly class Assignments
{
    public function __construct(
        private MakefileSources $sources,
        private ?Output $output,
        private ?Configuration $configuration,
    ) {}

    public static function assignment(Ast\AssignmentNode $node): Assignment
    {
        return new Assignment($node->name, $node->operator, $node->expression);
    }

    /**
     * @param list<string> $modifiers
     *
     * @return array{string, bool, ?bool}
     */
    public static function modifiers(array $modifiers): array
    {
        $origin = 'file';
        $private = false;
        $export = null;
        foreach ($modifiers as $modifier) {
            if ($modifier === 'override') {
                $origin = 'override';
            } elseif ($modifier === 'private') {
                $private = true;
            } else {
                $export = $modifier === 'export';
            }
        }
        return [$origin, $private, $export];
    }

    /**
     * @param list<string> $modifiers
     * @param array<string, Variable> $variables
     *
     * @throws MakefileErrorException
     */
    public function store(
        Assignment $assignment,
        array $modifiers,
        array &$variables,
        Exports $exports,
        VariableExpander $expander,
        bool $definition = false,
    ): void {
        [$origin, $private, $export] = self::modifiers($modifiers);
        if (!$definition && $export !== null) {
            $exports->set([$assignment->name], $export);
        }
        $assignment->apply($variables, $origin, $this->output, $expander->source, $expander, $private);
        if ($assignment->name === 'MAKEFLAGS') {
            $this->configuration?->updateMakeflags($variables, $expander, $origin);
            $variables['.INCLUDE_DIRS'] = new Variable(
                '.INCLUDE_DIRS',
                implode(' ', $this->sources->directories()),
                false,
                'default',
            );
        }
        if ($definition && $export !== null) {
            $exports->set([$assignment->name], $export);
        }
    }
}
