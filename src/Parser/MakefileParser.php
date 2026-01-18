<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use LogicException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\Target;

final readonly class MakefileParser
{
    /** @var list<string> */
    private array $makefileLines;

    private Target $firstTarget;

    public function __construct(string $makefile)
    {
        $this->makefileLines = explode("\n", $makefile);
        $this->firstTarget = $this->findFirstTarget() ?? throw new LogicException('Default target not found');
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

    private function findFirstTarget(): ?Target
    {
        foreach (array_keys($this->makefileLines) as $lineIndex) {
            $target = $this->getTarget($lineIndex);
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private function getTarget(int $lineIndex): ?Target
    {
        if (!$this->isTargetName($this->makefileLines[$lineIndex] ?? '')) {
            return null;
        }

        $targetName = explode(':', $this->makefileLines[$lineIndex])[0];
        $dependencyNames = array_filter(
            explode(' ', explode(':', $this->makefileLines[$lineIndex])[1] ?? ''),
            fn($dependencyName) => $dependencyName !== '',
        );

        $dependencies = [];
        foreach ($dependencyNames as $dependencyName) {
            $dependencyLineIndex = $this->getLineIndexOfTarget($dependencyName);
            if ($dependencyLineIndex === null) {
                continue;
            }
            $dependencyTarget = $this->getTarget($dependencyLineIndex);
            if ($dependencyTarget === null) {
                continue;
            }
            $dependencies[] = $dependencyTarget;
        }

        $commands = [];
        for (
            $line = $lineIndex + 1;
            isset($this->makefileLines[$line]) && !$this->isTargetName($this->makefileLines[$line]);
            $line++
        ) {
            $command = trim($this->makefileLines[$line]);
            if ($command === '') {
                continue;
            }
            $commands[] = $command;
        }

        return new Target($targetName, $dependencies, $lineIndex, $line - 1, $commands);
    }

    private function getLineIndexOfTarget(string $targetName): ?int
    {
        foreach (array_keys($this->makefileLines) as $lineIndex) {
            if (
                $this->isTargetName($this->makefileLines[$lineIndex])
                && explode(':', $this->makefileLines[$lineIndex])[0] === $targetName
            ) {
                return $lineIndex;
            }
        }

        return null;
    }

    private function isTargetName(string $line): bool
    {
        return preg_match('/^[.a-zA-Z0-9_-]+:/', $line) === 1;
    }
}
