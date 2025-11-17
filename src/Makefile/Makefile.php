<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

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
     *
     * @throws MakefileErrorException
     * @throws MakefileUpToDateException
     */
    public function run(array $targets, Shell $shell, Filesystem $filesystem, Output $output): void
    {
        if ($targets === []) {
            if (! $this->targets[0]->run($shell, $filesystem, $output)) {
                throw new MakefileUpToDateException($this->targets[0]->name);
            }
            return;
        }

        foreach ($targets as $target) {
            $foundTarget = $this->findTarget($target);
            if ($foundTarget === null) {
                throw new MakefileErrorException("No rule to make target `$target'");
            }
            if (! $foundTarget->run($shell, $filesystem, $output)) {
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