<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Files;

use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\Pattern;
use Tamiroh\Phmake\Makefile\Rule\Target;

use function in_array;

/**
 * Special targets govern intermediate lifetime independently of recipe selection.
 *
 * @internal
 */
final readonly class FilePolicy
{
    /** @var array<string, list<string>> */
    private array $special;

    /**
     * @param array<string, Target> $targets
     *
     * @throws MakefileErrorException
     */
    public function __construct(array $targets)
    {
        $special = [];
        foreach (['.INTERMEDIATE', '.SECONDARY', '.NOTINTERMEDIATE', '.PRECIOUS'] as $name) {
            if (isset($targets[$name])) {
                $special[$name] = $targets[$name]->rules[0]->prerequisites->normal ?? [];
            }
        }
        if (($special['.SECONDARY'] ?? null) === [] && ($special['.NOTINTERMEDIATE'] ?? null) === []) {
            throw new MakefileErrorException('.NOTINTERMEDIATE and .SECONDARY are mutually exclusive');
        }
        foreach ($special['.NOTINTERMEDIATE'] ?? [] as $name) {
            foreach (['.INTERMEDIATE', '.SECONDARY'] as $kind) {
                if (in_array($name, $special[$kind] ?? [], true)) {
                    throw new MakefileErrorException("$name cannot be both .NOTINTERMEDIATE and $kind");
                }
            }
        }
        $this->special = $special;
    }

    public function intermediate(string $name, bool $inferred): bool
    {
        if (
            in_array($name, $this->special['.INTERMEDIATE'] ?? [], true)
            || in_array($name, $this->special['.SECONDARY'] ?? [], true)
        ) {
            return true;
        }
        if (($this->special['.NOTINTERMEDIATE'] ?? null) === [] || $this->matches('.NOTINTERMEDIATE', $name)) {
            return false;
        }
        return $inferred || ($this->special['.SECONDARY'] ?? null) === [];
    }

    public function keep(string $name): bool
    {
        return (
            ($this->special['.SECONDARY'] ?? null) === []
            || in_array($name, $this->special['.SECONDARY'] ?? [], true)
            || $this->matches('.PRECIOUS', $name)
        );
    }

    private function matches(string $kind, string $name): bool
    {
        foreach ($this->special[$kind] ?? [] as $pattern) {
            if (new Pattern($pattern)->match($name) !== null) {
                return true;
            }
        }
        return false;
    }
}
