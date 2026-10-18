<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Filesystem\Internal;

use Closure;
use FFI;
use FFI\CData;
use FFI\Exception;
use LogicException;

use function extension_loaded;
use function in_array;
use function is_callable;
use function is_int;
use function php_uname;
use function str_contains;
use function unpack;

use const PHP_INT_SIZE;
use const PHP_OS_FAMILY;

/**
 * Cache native bindings, never file metadata. Each lookup calls the OS directly.
 * Buffers are 8-byte aligned and sized for the complete native output structure.
 */
final class NativeFileTimes
{
    private static self|false|null $instance = false;

    /**
     * @param Closure(mixed...): mixed $follow
     * @param Closure(mixed...): mixed $link
     */
    private function __construct(
        private readonly Closure $follow,
        private readonly Closure $link,
        private readonly CData $buffer,
        private readonly CData $bytes,
        private readonly bool $linux,
    ) {}

    public static function instance(): ?self
    {
        if (self::$instance !== false) {
            return self::$instance;
        }
        self::$instance = null;
        if (
            !extension_loaded('FFI')
            || PHP_INT_SIZE !== 8
            || !in_array(php_uname('m'), ['x86_64', 'amd64', 'arm64', 'aarch64'], true)
            || !in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)
        ) {
            return null;
        }
        try {
            $linux = PHP_OS_FAMILY === 'Linux';
            $ffi = self::required(FFI::cdef(
                $linux
                    ? 'int statx(int dirfd, const char *path, int flags, unsigned int mask, void *buf);'
                    : 'int stat64(const char *path, void *buf); int lstat64(const char *path, void *buf);',
            ));
            $buffer = self::required($ffi->new('uint64_t[32]'));
            $native = new self(
                self::bind($ffi, $linux ? 'statx' : 'stat64'),
                self::bind($ffi, $linux ? 'statx' : 'lstat64'),
                $buffer,
                self::required($ffi->cast('char *', FFI::addr($buffer))),
                $linux,
            );
            // Also detect unavailable statx kernels / sandbox restrictions before selecting this backend.
            if (is_int($native->modified('.'))) {
                self::$instance = $native;
            }
        } catch (Exception) {
            // FFI can be installed but disabled, or libc may lack the requested symbols.
        }
        return self::$instance;
    }

    /**
     * @return Closure(mixed...): mixed
     */
    private static function bind(object $library, string $name): Closure
    {
        $call = [$library, $name];
        if (!is_callable($call)) {
            throw new LogicException('Unavailable native function: ' . $name);
        }
        return static fn(mixed ...$arguments): mixed => $call(...$arguments);
    }

    /**
     * @template T of object
     *
     * @param T|null $value
     *
     * @return T
     */
    private static function required(?object $value): object
    {
        if ($value === null) {
            throw new LogicException('Native timestamp allocation failed.');
        }
        return $value;
    }

    /**
     * Return false when the caller should fall back to the portable stat adapter.
     */
    public function modified(string $path, bool $follow = true): int|false|null
    {
        // C strings cannot represent embedded NUL bytes; do not silently stat a truncated path.
        if (str_contains($path, "\0")) {
            return null;
        }
        $call = $follow ? $this->follow : $this->link;
        if ($this->linux) {
            // AT_FDCWD, AT_NO_AUTOMOUNT | AT_SYMLINK_NOFOLLOW, STATX_MTIME.
            if ($call(-100, $path, 0x800 | ($follow ? 0 : 0x100), 0x40, $this->buffer) !== 0) {
                return false;
            }
        } elseif ($call($path, $this->buffer) !== 0) {
            return false;
        }
        $bytes = FFI::string($this->bytes, 256);
        if ($this->linux) {
            $mask = unpack('Lmask', $bytes);
            if ($mask === false || !is_int($mask['mask'] ?? null) || ($mask['mask'] & 0x40) === 0) {
                return false;
            }
        }
        // Linux UAPI struct statx: stx_mtime at 112, signed 64-bit seconds + unsigned 32-bit ns.
        // Darwin 64-bit struct stat64: st_mtimespec at 48, two signed 64-bit longs.
        $time = unpack(
            $this->linux ? 'qseconds/Lnanoseconds' : 'qseconds/qnanoseconds',
            $bytes,
            $this->linux ? 112 : 48,
        );
        if ($time === false || !is_int($time['seconds'] ?? null) || !is_int($time['nanoseconds'] ?? null)) {
            throw new LogicException('Invalid native timestamp buffer.');
        }
        return ($time['seconds'] * 1_000_000_000) + $time['nanoseconds'];
    }
}
