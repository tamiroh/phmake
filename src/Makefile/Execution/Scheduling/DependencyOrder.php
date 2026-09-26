<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Scheduling;

use Tamiroh\Phmake\Makefile\Makefile;

use function array_reverse;
use function hash;
use function in_array;
use function strcmp;
use function usort;

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
        usort($names, static fn(string $left, string $right): int => strcmp(
            hash('sha256', $options->shuffle . "\0" . $left),
            hash('sha256', $options->shuffle . "\0" . $right),
        ));
        return $names;
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
}
