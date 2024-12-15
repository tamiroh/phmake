<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use DateTime;
use Tamiroh\Phmake\Exceptions\MakefileException;

readonly final class Makefile
{
    /**
     * @param list<Target> $targets
     */
    public function __construct(
        public array $targets = [],
    ) {}

    /**
     * @param array<string, ?DateTime> $lastModified
     *
     * @throws MakefileException
     */
    public function run(string|null $target, array $lastModified): void
    {
        if ($target === null) {
            $this->targets[0]->run();
            return;
        }

        $foundTarget = $this->findTarget($target);
        if ($foundTarget === null) {
            throw new MakefileException("No rule to make target `$target'");
        }

        if ($lastModified[$foundTarget->name] === null) {
            $foundTarget->run();
        } else {
            // TODO: Check dependencies
            // TODO: `echo` should be moved to Application
            echo 'phmake: `' . $foundTarget->name . '\' is up to date.' . PHP_EOL;
        }
    }

    private function findTarget(string $target): ?Target
    {
        $foundTarget = null;
        foreach ($this->targets as $makefileTarget) {
            if ($makefileTarget->name === $target) {
                $foundTarget = $makefileTarget;
                break;
            }
        }
        return $foundTarget;
    }
}