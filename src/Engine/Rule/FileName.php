<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Rule;

/**
 * A leading current-directory component does not distinguish make targets.
 *
 * @internal
 */
final class FileName
{
    public static function normalize(string $name): string
    {
        return preg_replace('~^(?:\./)+(?=.)~', '', $name) ?? $name;
    }
}
