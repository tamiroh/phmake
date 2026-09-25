<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function ltrim;
use function strspn;
use function substr;

final readonly class Command
{
    public function __construct(
        public string $expression,
        public ?string $source = null,
    ) {}

    /**
     * @param list<Variable>|VariableExpander $variables
     *
     * @throws MakefileErrorException
     */
    public function expand(array|VariableExpander $variables, Output $output): ExpandedCommand
    {
        return new ExpandedCommand(
            ($variables instanceof VariableExpander
                ? $variables->atSource($this->source)
                : new VariableExpander($variables, $output, source: $this->source))->expand($this->expression),
            substr(ltrim($this->expression), 0, strspn(ltrim($this->expression), '@-+')),
        );
    }

    /**
     * @param list<Variable>|VariableExpander $variables
     *
     * @throws MakefileErrorException
     */
    public function run(Shell $shell, Output $output, array|VariableExpander $variables = []): int
    {
        return $this->expand($variables, $output)->run($shell, $output);
    }
}
