<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Files;

/**
 * Preserve file-table traversal order for intermediate cleanup diagnostics.
 *
 * @internal
 */
final class FileTable
{
    /** @var array<int, string> */
    private array $slots = [];

    private int $size = 1024;

    public function enter(string $name): void
    {
        $slot = FileHash::value($name) & ($this->size - 1);
        while (isset($this->slots[$slot])) {
            if ($this->slots[$slot] === $name) {
                return;
            }
            $slot = ($slot + 1) & ($this->size - 1);
        }
        $this->slots[$slot] = $name;
        if (count($this->slots) > ($this->size - ($this->size >> 4))) {
            ksort($this->slots);
            $names = $this->slots;
            $this->slots = [];
            $this->size *= 2;
            foreach ($names as $entry) {
                $this->enter($entry);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        ksort($this->slots);
        return array_values($this->slots);
    }
}
