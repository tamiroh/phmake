<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use DateTime;

readonly final class Makefile
{
    /**
     * @param list<Target> $targets
     */
    public function __construct(
        public array $targets = [],
    ) {}

    /**
     * @param list<string> $targets
     * @param array<string, ?DateTime> $lastModified
     *
     * @throws MakefileErrorException
     * @throws MakefileUpToDateException
     */
    public function run(array $targets, array $lastModified): void
    {
        if ($targets === []) {
            $this->targets[0]->run();
            return;
        }

        foreach ($targets as $target) {
            $foundTarget = $this->findTarget($target);
            if ($foundTarget === null) {
                throw new MakefileErrorException("No rule to make target `$target'");
            }

            if ($lastModified[$foundTarget->name] === null) {
                $foundTarget->run();
            } else {
                // TODO: Check dependencies
                throw new MakefileUpToDateException($foundTarget->name);
            }
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