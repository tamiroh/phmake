<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function explode;
use function in_array;
use function rtrim;
use function str_replace;
use function strtolower;

/**
 * Control recipe visibility and tracing independently from execution modes.
 */
final class ReportingOptions
{
    public bool $silent = false;

    public bool $trace = false;

    public bool $remaking = false;

    /** @var list<string> */
    public private(set) array $debugLevels = [];

    public bool $print {
        get => $this->enabled('p');
    }

    public bool $why {
        get => $this->enabled('w');
    }

    /**
     * @throws MakefileErrorException
     */
    public function addDebugFlags(string $levels): void
    {
        foreach (explode(',', str_replace(' ', ',', rtrim($levels, ', '))) as $level) {
            if (!in_array(strtolower($level[0] ?? ''), ['p', 'w', 'n'], true)) {
                throw new MakefileErrorException("Debug level '$level' is not supported");
            }
        }
        if (!in_array($levels, $this->debugLevels, true)) {
            $this->debugLevels[] = $levels;
        }
    }

    private function enabled(string $selected): bool
    {
        if ($this->remaking) {
            return false;
        }
        $enabled = $this->trace;
        foreach ($this->debugLevels as $levels) {
            foreach (explode(',', str_replace(' ', ',', $levels)) as $level) {
                $initial = strtolower($level[0] ?? '');
                if ($initial === 'n') {
                    $enabled = false;
                } elseif ($initial === $selected) {
                    $enabled = true;
                }
            }
        }
        return $enabled;
    }
}
