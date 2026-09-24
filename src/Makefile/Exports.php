<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function in_array;
use function max;
use function preg_match;

final class Exports
{
    /** @var array<string, bool> */
    private array $directives = [];

    private ?bool $all = null;

    /** @param list<string> $inherited */
    public function __construct(
        private readonly array $inherited = [],
    ) {}

    /**
     * @param list<Variable> $variables
     * @return array<string, string|false>
     * @throws MakefileErrorException
     */
    public function environment(array $variables, Output $output): array
    {
        $environment = [];
        foreach ($this->directives as $name => $export) {
            if (!$export) {
                $environment[$name] = false;
            }
        }
        $expander = new VariableExpander($variables, $output);
        foreach ($variables as $variable) {
            $export =
                $this->directives[$variable->name]
                ?? !in_array($variable->origin, ['default', 'automatic'], true)
                    && (
                        in_array($variable->origin, ['environment', 'environment override', 'command line'], true)
                        || ($this->all ?? in_array($variable->name, $this->inherited, true))
                    );
            if (
                $export
                && (
                    isset($this->directives[$variable->name])
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $variable->name) === 1
                )
            ) {
                $environment[$variable->name] = in_array(
                    $variable->origin,
                    ['environment', 'environment override'],
                    true,
                )
                    ? $variable->expression
                    : $expander->expand('$(' . $variable->name . ')');
            } elseif (in_array($variable->name, $this->inherited, true)) {
                $environment[$variable->name] = false;
            }
        }
        $level = $expander->variable('MAKELEVEL');
        if ($level !== null) {
            $environment['MAKELEVEL'] = (string) (max(0, (int) $expander->expand('$(MAKELEVEL)')) + 1);
        }
        return $environment;
    }

    /** @param list<string> $names */
    public function set(array $names, bool $export): void
    {
        if ($names === []) {
            $this->all = $export;
            return;
        }
        foreach ($names as $name) {
            $this->directives[$name] = $export;
        }
    }
}
