<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Rule;

use function array_keys;

/**
 * Literal file attributes from special declarations, including every double-colon entry.
 * Pattern names are keys here; attributes are inherited only when that rule is selected.
 */
final class SpecialTargets
{
    /** @var array<string, array<string, true>> */
    private array $members = [];

    /** @var array<string, true> */
    private array $empty = [];

    /**
     * @param array<string, Target> $targets
     */
    public function __construct(array $targets)
    {
        foreach (['.INTERMEDIATE', '.SECONDARY', '.NOTINTERMEDIATE', '.PRECIOUS', '.LOW_RESOLUTION_TIME'] as $special) {
            foreach ($targets[$special]->rules ?? [] as $rule) {
                if ($rule->prerequisites->sequence === []) {
                    $this->empty[$special] = true;
                }
                foreach ([...$rule->prerequisites->normal, ...$rule->prerequisites->orderOnly] as $name) {
                    $this->members[$special][$name] = true;
                }
            }
        }
    }

    public function contains(string $special, string $name): bool
    {
        return isset($this->members[$special][$name]);
    }

    public function hasEmptyRule(string $special): bool
    {
        return isset($this->empty[$special]);
    }

    /**
     * @return list<string>
     */
    public function names(string $special): array
    {
        return array_keys($this->members[$special] ?? []);
    }
}
