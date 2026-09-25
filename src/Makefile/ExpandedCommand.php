<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function explode;
use function ltrim;
use function rtrim;
use function str_contains;
use function strlen;
use function strspn;
use function substr;

final readonly class ExpandedCommand
{
    public function __construct(
        public string $expression,
        private string $prefix = '',
    ) {}

    /**
     * @throws MakefileErrorException
     */
    public function run(Shell $shell, Output $output): int
    {
        $pending = '';
        foreach (explode("\n", $this->expression) as $line) {
            $pending .= $line;
            if (((strlen($line) - strlen(rtrim($line, '\\'))) % 2) === 1) {
                $pending .= "\n";
                continue;
            }
            $exitCode = $this->runLine($pending, $shell, $output);
            if ($exitCode !== 0) {
                return $exitCode;
            }
            $pending = '';
        }
        return $pending === '' ? 0 : $this->runLine($pending, $shell, $output);
    }

    /**
     * @throws MakefileErrorException
     */
    private function runLine(string $line, Shell $shell, Output $output): int
    {
        $expanded = ltrim($line);
        $prefixLength = strspn($expanded, '@-+');
        $prefix = $this->prefix . substr($expanded, 0, $prefixLength);
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
