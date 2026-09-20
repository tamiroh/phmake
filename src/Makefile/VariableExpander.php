<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

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
            function (array $matches) use ($expanding): string {
                if ($matches[0] === '$$') {
                    return '$';
                }
                $name = ($matches[1] ?? '') !== '' ? $matches[1] : $matches[2] ?? '';
                $variable = $this->variables[$name] ?? null;
                if ($variable === null) {
                    return '';
                }
                if (!$variable->recursive) {
                    return $variable->expression;
                }
                if (in_array($name, $expanding, true)) {
                    throw new MakefileErrorException("Recursive variable `$name'");
                }
                return $this->expand($variable->expression, [...$expanding, $name]);
            },
            $expression,
        ) ?? $expression;
    }
}
