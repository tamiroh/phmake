<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Rule;

/**
 * A leading current-directory component does not distinguish make targets.
 */
final class FileName
{
    public static function normalize(string $name): string
    {
        return preg_replace('~^(?:\./)+(?=.)~', '', $name) ?? $name;
    }
}
