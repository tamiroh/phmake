<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function ltrim;
use function str_contains;
use function strspn;
use function substr;

final readonly class ExpandedCommand
{
    public function __construct(
        public string $expression,
    ) {}

    public function run(Shell $shell, Output $output): int
    {
        $expanded = ltrim($this->expression);
        $prefixLength = strspn($expanded, '@-+');
        $prefix = substr($expanded, 0, $prefixLength);
        $expanded = ltrim(substr($expanded, $prefixLength));
        if ($expanded === '') {
            return 0;
        }
        if (!str_contains($prefix, '@')) {
            $output->writeLine($expanded);
        }
        $exitCode = $shell->exec($expanded);
        if ($exitCode !== 0 && str_contains($prefix, '-')) {
            $output->writeWarning("Error {$exitCode} (ignored)");
            return 0;
        }
        return $exitCode;
    }
}
