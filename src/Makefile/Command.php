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
    public function run(Shell $shell, Output $output, array $variables = []): int
    {
        $expanded = $this->expand($variables);
        $output->writeLine($expanded);
        return $shell->exec($expanded);
    }

    /**
     * @param list<Variable> $variables
     * @throws MakefileErrorException
     */
    private function expand(array $variables): string
    {
        return new VariableExpander($variables)->expand($this->expression);
    }
}
