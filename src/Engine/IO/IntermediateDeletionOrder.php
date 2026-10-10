<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\IO;

/**
 * Preserve the observed order of intermediate deletions and their rm message.
 * This is a compatibility detail, not a specified ordering guarantee.
 */
interface IntermediateDeletionOrder
{
    /**
     * Remember each file when make first encounters it, including non-intermediates.
     */
    public function enter(string $name): void;

    /**
     * @return list<string>
     */
    public function names(): array;
}
