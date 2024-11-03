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

    public function run(string|null $target): void
    {
        if ($target === null) {
            $this->targets[0]->run();
            return;
        }

        $foundTarget = null;
        foreach ($this->targets as $makefileTarget) {
            if ($makefileTarget->name === $target) {
                $foundTarget = $makefileTarget;
                break;
            }
        }

        $foundTarget?->run();
    }
}