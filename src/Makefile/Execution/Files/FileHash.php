<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Files;

/**
 * Jenkins lookup3 string hashing used by GNU make's file table.
 *
 * @internal
 */
final class FileHash
{
    /**
     * @pure
     */
    public static function value(string $name): int
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
}
