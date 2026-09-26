<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Reporting;

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

    public bool $debugAll = false;

    public bool $warnUndefinedVariables = false;

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
            if (!in_array(strtolower($level[0] ?? ''), ['a', 'b', 'i', 'j', 'm', 'n', 'p', 'v', 'w'], true)) {
                throw new MakefileErrorException("unknown debug level specification '$level'");
            }
        }
        if (!in_array($levels, $this->debugLevels, true)) {
            $this->debugLevels[] = $levels;
        }
    }

    public function enabled(string $selected): bool
    {
        if ($this->remaking && $selected !== 'm' && !$this->enabled('m')) {
            return false;
        }
        $enabled = $this->debugAll || $this->trace && in_array($selected, ['p', 'w'], true);
        foreach ($this->debugLevels as $levels) {
            foreach (explode(',', str_replace(' ', ',', $levels)) as $level) {
                $initial = strtolower($level[0] ?? '');
                if ($initial === 'n') {
                    $enabled = false;
                } elseif (
                    $initial === 'a'
                    || $initial === $selected
                    || $selected === 'b' && in_array($initial, ['i', 'm', 'v'], true)
                ) {
                    $enabled = true;
                }
            }
        }
        return $enabled;
    }
}
