<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function in_array;
use function preg_replace_callback;

final readonly class VariableExpander
{
    /** @var array<string, Variable> */
    private array $variables;

    /** @param list<Variable> $variables */
    public function __construct(array $variables)
    {
        $indexed = [];
        foreach ($variables as $variable) {
            $indexed[$variable->name] = $variable;
        }
        $this->variables = $indexed;
    }

    /**
     * @param list<string> $expanding
     * @throws MakefileErrorException
     */
    public function expand(string $expression, array $expanding = []): string
    {
        return preg_replace_callback(
            '/\$\$|\$\(([A-Za-z_][A-Za-z0-9_]*)\)|\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            /** @throws MakefileErrorException */
            function (array $matches) use ($expanding): string {
                /** @var array{0: string, 1?: string, 2?: string} $matches */
                if ($matches[0] === '$$') {
                    return '$';
                }
                $name = isset($matches[1]) && $matches[1] !== '' ? $matches[1] : $matches[2] ?? '';
                $variable = $this->variables[$name] ?? null;
                if ($variable === null) {
                    return '';
                }
                if (!$variable->recursive) {
                    return $variable->expression;
                }
                if (in_array($name, $expanding, strict: true)) {
                    throw new MakefileErrorException("Recursive variable `$name'");
                }
                return $this->expand($variable->expression, [...$expanding, $name]);
            },
            $expression,
        ) ?? $expression;
    }
}
