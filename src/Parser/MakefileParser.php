<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use LogicException;
use Tamiroh\Phmake\Makefile\Command;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Makefile\Variable;

final readonly class MakefileParser
{
    private const string PHONY_TARGET = '.PHONY';

    /** @var list<string> */
    private array $makefileLines;

    /** @var list<string> */
    private array $phonyTargets;

    /** @var list<Variable> */
    private array $variables;

    private Target $firstTarget;

    public function __construct(string $makefile)
    {
        $this->makefileLines = explode("\n", $makefile);
        $this->phonyTargets = $this->findPhonyTargets();
        $this->variables = $this->findVariables();
        $this->firstTarget = $this->findFirstTarget() ?? throw new LogicException('Default target not found');
    }

    public function parse(): Makefile
    {
        $targets = [$this->firstTarget];
        $currentTarget = $this->firstTarget;
        while (true) {
            $nextTarget = $this->findNextTarget($currentTarget->endLineIndex + 1);
            if ($nextTarget === null) {
                break;
            }
            $targets[] = $nextTarget;
            $currentTarget = $nextTarget;
        }

        return new Makefile($targets, $this->variables);
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

    private function findNextTarget(int $lineIndex): ?Target
    {
        for ($line = $lineIndex; isset($this->makefileLines[$line]); $line++) {
            $target = $this->getTarget($line);
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
        if ($targetName === self::PHONY_TARGET) {
            return null;
        }

        $dependencyNames = array_filter(
            explode(' ', explode(':', $this->makefileLines[$lineIndex])[1] ?? ''),
            static fn($dependencyName) => $dependencyName !== '',
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
            isset($this->makefileLines[$line])
            && !$this->isTargetName($this->makefileLines[$line])
            && !$this->isVariableDefinition($this->makefileLines[$line]);
            $line++
        ) {
            $command = trim($this->makefileLines[$line]);
            if ($command === '') {
                continue;
            }
            $commands[] = new Command($command);
        }

        return new Target(
            $targetName,
            $dependencies,
            $lineIndex,
            $line - 1,
            $commands,
            in_array($targetName, $this->phonyTargets, true),
        );
    }

    private function getLineIndexOfTarget(string $targetName): ?int
    {
        foreach (array_keys($this->makefileLines) as $lineIndex) {
            if (
                !(
                    $this->isTargetName($this->makefileLines[$lineIndex])
                    && explode(':', $this->makefileLines[$lineIndex])[0] === $targetName
                )
            ) {
                continue;
            }

            return $lineIndex;
        }

        return null;
    }

    private function isTargetName(string $line): bool
    {
        return preg_match('/^[.a-zA-Z0-9_-]+:/', $line) === 1;
    }

    private function isVariableDefinition(string $line): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private function findPhonyTargets(): array
    {
        $phonyTargets = [];

        foreach ($this->makefileLines as $line) {
            if (!str_starts_with($line, self::PHONY_TARGET . ':')) {
                continue;
            }

            $targetNames = array_filter(
                explode(' ', explode(':', $line)[1] ?? ''),
                static fn(string $targetName): bool => $targetName !== '',
            );

            foreach ($targetNames as $targetName) {
                $phonyTargets[] = $targetName;
            }
        }

        return array_values(array_unique($phonyTargets));
    }

    /**
     * @return list<Variable>
     */
    private function findVariables(): array
    {
        $variables = [];

        foreach ($this->makefileLines as $line) {
            if (!$this->isVariableDefinition($line)) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $variables[] = new Variable(trim($name), trim($value));
        }

        return $variables;
    }
}
