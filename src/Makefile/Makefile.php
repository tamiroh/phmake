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

    public function run(string $target): void
    {
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