<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Command;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\Target;

readonly final class MakefileParser
{
    /** @var list<string> */
    private array $makefileLines;

    private Target $firstTarget;

    public function __construct(string $makefile)
    {
        $this->makefileLines = explode("\n", $makefile);
        $this->firstTarget = $this->getTarget(0);
    }

    public function parse(): Makefile
    {
        $targets = [$this->firstTarget];
        $currentTarget = $this->firstTarget;
        while (true) {
            $nextTarget = $this->getTarget($currentTarget->endLineIndex + 1);
            if ($nextTarget === null) {
                break;
            }
            $targets[] = $nextTarget;
            $currentTarget = $nextTarget;
        }

        return new Makefile($targets);
    }

    private function getTarget(int $lineIndex): ?Target
    {
        if (! $this->isTargetName($this->makefileLines[$lineIndex] ?? '')) {
            return null;
        }

        $targetName = str_replace(':', '', $this->makefileLines[$lineIndex]);

        $commands = [];
        for ($line = $lineIndex + 1; isset($this->makefileLines[$line]) && ! $this->isTargetName($this->makefileLines[$line]); $line++) {
            $commands[] = new Command(trim($this->makefileLines[$line]));
        }

        return new Target($targetName, $lineIndex, $line - 1, $commands);
    }

    private function isTargetName(string $line): bool
    {
        return str_ends_with($line, ':');
    }
}