<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Scheduling;

use Tamiroh\Phmake\Makefile\Makefile;

use function array_reverse;
use function hash;
use function in_array;
use function strcmp;
use function usort;

/**
 * @internal
 */
final class DependencyOrder
{
    /**
     * Reorder traversal without changing prerequisite order in automatic variables.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function arrange(
        array $names,
        ParallelOptions $options,
        Makefile $makefile,
        ?string $target = null,
    ): array {
        if ($options->shuffle === null || self::serial($makefile, $target) || in_array('.WAIT', $names, true)) {
            return $names;
        }
        if ($options->shuffle === 'reverse') {
            return array_reverse($names);
        }
        return self::shuffle($names, $options->shuffle);
    }

    public static function serial(Makefile $makefile, ?string $name = null): bool
    {
        foreach ($makefile->targetsByName['.NOTPARALLEL']->rules ?? [] as $rule) {
            if ($rule->prerequisites->normal === [] || in_array($name, $rule->prerequisites->normal, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Order names randomly; the same seed always gives the same order.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function shuffle(array $names, string $seed): array
    {
        usort($names, static fn(string $left, string $right): int => strcmp(
            hash('sha256', $seed . "\0" . $left),
            hash('sha256', $seed . "\0" . $right),
        ));
        return $names;
    }
}
