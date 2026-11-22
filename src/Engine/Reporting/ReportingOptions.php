<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Reporting;

use Tamiroh\Phmake\Engine\MakefileErrorException;

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
        foreach (explode(',', str_replace(' ', replace: ',', subject: rtrim($levels, characters: ', '))) as $level) {
            if (!in_array(strtolower($level[0] ?? ''), ['a', 'b', 'i', 'j', 'm', 'n', 'p', 'v', 'w'], strict: true)) {
                throw new MakefileErrorException("unknown debug level specification '{$level}'");
            }
        }
        if (!in_array($levels, $this->debugLevels, strict: true)) {
            $this->debugLevels[] = $levels;
        }
    }

    public function enabled(string $selected): bool
    {
        if ($this->remaking && $selected !== 'm' && !$this->enabled('m')) {
            return false;
        }
        $enabled = $this->debugAll || $this->trace && in_array($selected, ['p', 'w'], strict: true);
        foreach ($this->debugLevels as $levels) {
            foreach (explode(',', str_replace(' ', replace: ',', subject: $levels)) as $level) {
                $initial = strtolower($level[0] ?? '');
                if ($initial === 'n') {
                    $enabled = false;
                } elseif (
                    $initial === 'a'
                    || $initial === $selected
                    || $selected === 'b' && in_array($initial, ['i', 'm', 'v'], strict: true)
                ) {
                    $enabled = true;
                }
            }
        }
        return $enabled;
    }
}
