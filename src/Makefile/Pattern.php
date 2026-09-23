<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

final readonly class Pattern
{
    public function __construct(
        private string $expression,
    ) {}

    public function match(string $name): ?string
    {
        $percent = strpos($this->expression, '%');
        if ($percent === false) {
            return $name === $this->expression ? '' : null;
        }
        $prefix = substr($this->expression, 0, $percent);
        $suffix = substr($this->expression, $percent + 1);
        if (
            !str_starts_with($name, $prefix)
            || !str_ends_with($name, $suffix)
            || strlen($name) < (strlen($prefix) + strlen($suffix))
        ) {
            return null;
        }
        return substr($name, strlen($prefix), strlen($name) - strlen($prefix) - strlen($suffix));
    }

    public function substitute(string $stem): string
    {
        $percent = strpos($this->expression, '%');
        return $percent === false
            ? $this->expression
            : substr($this->expression, 0, $percent) . $stem . substr($this->expression, $percent + 1);
    }
}
