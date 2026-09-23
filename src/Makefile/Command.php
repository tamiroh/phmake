<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function ltrim;
use function str_contains;
use function strspn;
use function substr;

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
        $expanded = ltrim($this->expand($variables));
        $prefixLength = strspn($expanded, '@-+');
        $prefix = substr($expanded, 0, $prefixLength);
        $expanded = ltrim(substr($expanded, $prefixLength));
        if ($expanded === '') {
            return 0;
        }
        if (!str_contains($prefix, '@')) {
            $output->writeLine($expanded);
        }
        $exitCode = $shell->exec($expanded);
        if ($exitCode !== 0 && str_contains($prefix, '-')) {
            $output->writeWarning("Error {$exitCode} (ignored)");
            return 0;
        }
        return $exitCode;
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
