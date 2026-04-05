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
     */
    public function expand(array $variables): string
    {
        return (
            preg_replace_callback(
                '/\$\(([A-Za-z_][A-Za-z0-9_]*)\)/',
                fn(array $matches): string => ((
                    $this->findVariable($matches[1], $variables) ?? new Variable('', '')
                ))->expression,
                $this->expression,
            ) ?? $this->expression
        );
    }

    /**
     * @param list<Variable> $variables
     */
    private function findVariable(string $name, array $variables): ?Variable
    {
        foreach ($variables as $variable) {
            if ($variable->name !== $name) {
                continue;
            }

            return $variable;
        }

        return null;
    }
}
