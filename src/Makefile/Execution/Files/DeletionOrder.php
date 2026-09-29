<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Files;

use function array_values;
use function count;
use function ksort;
use function ord;
use function strlen;

/**
 * The order in which GNU make deletes intermediate files and lists them in its rm message.
 *
 * GNU make deletes them in the order of its internal file table, so this reproduces that table:
 * Jenkins lookup3 string hashing with linear probing. Names are hashed only when listed,
 * since most builds never delete intermediate files.
 *
 * @internal
 */
final class DeletionOrder
{
    /**
     * Names in first-entered order; values keep numeric names as strings.
     *
     * @var array<string, string>
     */
    private array $entered = [];

    /**
     * @pure
     */
    private static function hash(string $name): int
    {
        $a = $b = $c = 0xdead_beef;
        $offset = 0;
        while (true) {
            $a = ($a + self::word($name, $offset)) & 0xffff_ffff;
            if ((strlen($name) - $offset) < 4) {
                break;
            }
            $offset += 4;
            $b = ($b + self::word($name, $offset)) & 0xffff_ffff;
            if ((strlen($name) - $offset) < 4) {
                break;
            }
            $offset += 4;
            $c = ($c + self::word($name, $offset)) & 0xffff_ffff;
            if ((strlen($name) - $offset) < 4) {
                break;
            }
            $offset += 4;
            foreach ([4, 6, 8, 16, 19, 4] as $rotation) {
                $a = (($a - $c) & 0xffff_ffff) ^ self::rotate($c, $rotation);
                $c = ($c + $b) & 0xffff_ffff;
                [$a, $b, $c] = [$b, $c, $a];
            }
        }
        foreach ([14, 11, 25, 16, 4, 14, 24] as $rotation) {
            $c = (($c ^ $b) - self::rotate($b, $rotation)) & 0xffff_ffff;
            [$a, $b, $c] = [$b, $c, $a];
        }
        return ($b + $offset) & 0xffff_ffff;
    }

    /**
     * @param array<int, string> $slots
     */
    private static function insert(string $name, array &$slots, int &$size): void
    {
        $slot = self::hash($name) & ($size - 1);
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

    /**
     * @pure
     */
    private static function rotate(int $value, int $count): int
    {
        return (($value << $count) | ($value >> (32 - $count))) & 0xffff_ffff;
    }

    /**
     * @pure
     */
    private static function word(string $name, int $offset): int
    {
        $word = 0;
        for ($index = 0; $index < 4 && isset($name[$offset + $index]); $index++) {
            $word |= ord($name[$offset + $index]) << (8 * $index);
        }
        return $word;
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
