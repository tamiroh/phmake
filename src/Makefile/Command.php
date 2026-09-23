<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final readonly class Command
{
    public function __construct(
        public string $expression,
    ) {}

    /**
     * @param list<Variable> $variables
     * @throws MakefileErrorException
     */
    public function expand(array $variables, Output $output): ExpandedCommand
    {
        return new ExpandedCommand(new VariableExpander($variables, $output)->expand($this->expression));
    }

    /**
     * @param list<Variable> $variables
     * @throws MakefileErrorException
     */
    public function run(Shell $shell, Output $output, array $variables = []): int
    {
        return $this->expand($variables, $output)->run($shell, $output);
    }
}
