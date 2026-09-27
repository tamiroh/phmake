<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Files;

use function array_values;
use function count;
use function ksort;

/**
 * Preserve file-table traversal order for intermediate cleanup diagnostics.
 *
 * Names are hashed only when listed, since most builds never need the order.
 *
 * @internal
 */
final class FileTable
{
    /**
     * Names in first-entered order; values keep numeric names as strings.
     *
     * @var array<string, string>
     */
    private array $entered = [];

    /**
     * @param array<int, string> $slots
     */
    private static function insert(string $name, array &$slots, int &$size): void
    {
        $slot = FileHash::value($name) & ($size - 1);
        while (isset($slots[$slot])) {
            if ($slots[$slot] === $name) {
                return;
            }
            $slot = ($slot + 1) & ($size - 1);
        }
        $slots[$slot] = $name;
        if (count($slots) > ($size - ($size >> 4))) {
            ksort($slots);
            $names = $slots;
            $slots = [];
            $size *= 2;
            foreach ($names as $entry) {
                self::insert($entry, $slots, $size);
            }
        }
    }

    public function enter(string $name): void
    {
        $this->entered[$name] ??= $name;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $slots = [];
        $size = 1024;
        foreach ($this->entered as $name) {
            self::insert($name, $slots, $size);
        }
        ksort($slots);
        return array_values($slots);
    }
}
