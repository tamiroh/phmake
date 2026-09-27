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

    /**
     * Block briefly until a running job may have finished or another make may have released a slot.
     */
    public function waitForJobs(): void;
}
