<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Scheduling;

use Tamiroh\Phmake\Engine\Makefile;

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
    public static function arrange(array $names, ParallelOptions $options, Makefile $makefile): array
    {
        if ($options->shuffle === null || self::serial($makefile) || in_array('.WAIT', $names, true)) {
            return $names;
        }
        if ($options->shuffle === 'reverse') {
            return array_reverse($names);
        }
        return self::shuffle($names, $options->shuffle);
    }

    public static function serial(Makefile $makefile): bool
    {
        $rule = $makefile->targetsByName['.NOTPARALLEL']->rules[0] ?? null;
        return $rule !== null && $rule->prerequisites->sequence === [];
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
