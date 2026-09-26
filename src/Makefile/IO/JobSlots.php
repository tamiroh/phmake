<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

interface JobSlots
{
    /**
     * Return a slot token, or null when all slots are occupied.
     */
    public function acquire(): ?string;

    public function release(string $slot): void;
}
