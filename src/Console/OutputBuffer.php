<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Fiber;
use RuntimeException;
use Tamiroh\Phmake\Makefile\Execution\ParallelOptions;
use WeakMap;

use function fclose;
use function flock;
use function fopen;
use function fseek;
use function fstat;
use function ftruncate;
use function rewind;
use function str_starts_with;
use function stream_get_meta_data;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const LOCK_EX;
use const LOCK_UN;
use const SEEK_END;
use const STDERR;
use const STDOUT;

/**
 * Per-fiber recipe buffers, serialized across recursive makes by a shared lock.
 */
final class OutputBuffer
{
    /** @var WeakMap<object, OutputGroup> */
    private WeakMap $groups;

    private ?OutputGroup $root = null;

    /** @var resource|null */
    private mixed $lock = null;

    private ?string $owned = null;

    /** @var array{string, string}|null */
    public ?array $directory = null;

    public function __construct(
        public ParallelOptions $options,
    ) {
        $this->groups = new WeakMap();
    }

    public function beginTarget(): void
    {
        $this->prepare();
        if ($this->options->sync === 'none') {
            return;
        }
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->root = new OutputGroup();
        } else {
            $this->groups[$fiber] = new OutputGroup();
        }
    }

    public function command(bool $begin, bool $recursive = false): void
    {
        $group = $this->current();
        if ($group === null) {
            return;
        }
        if ($begin && $group->started && $this->options->sync === 'line' && $this->options->jobs !== 1) {
            // Flush after the previous command's failure handling has joined its output.
            $this->flush($group);
        }
        if ($begin) {
            $group->started = true;
        }
        if ($begin && $recursive && $this->options->sync !== 'recurse') {
            $this->flush($group);
            $group->paused = true;
        } elseif (!$begin) {
            $group->paused = false;
        }
    }

    /**
     * @return array{1?: resource, 2?: resource}
     */
    public function descriptors(): array
    {
        $group = $this->current();
        if ($group === null || $group->paused) {
            return [];
        }
        fseek($group->out, 0, SEEK_END);
        fseek($group->err, 0, SEEK_END);
        return [1 => $group->out, 2 => $group->err];
    }

    public function endTarget(): void
    {
        if (($group = $this->current()) !== null) {
            $this->flush($group);
        }
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->root = null;
        } else {
            unset($this->groups[$fiber]);
        }
    }

    /**
     * Create the lock before publishing MAKEFLAGS to child makes.
     */
    public function prepare(): void
    {
        if ($this->options->sync === 'none' || $this->lock !== null) {
            if ($this->owned !== null) {
                $this->options->mutex = 'fnm:' . $this->owned;
            }
            return;
        }
        if ($this->options->mutex !== null && str_starts_with($this->options->mutex, 'fnm:')) {
            $path = substr($this->options->mutex, 4);
        } else {
            $path = @tempnam(sys_get_temp_dir(), 'phmake-output-');
            if ($path === false) {
                $this->options->sync = 'none';
                fwrite(STDERR, "phmake: warning: cannot create temporary file: suppressing output-sync.\n");
                return;
            }
            $this->owned = $path;
            $this->options->mutex = 'fnm:' . $path;
        }
        $lock = fopen($path, 'c+');
        if ($lock === false) {
            throw new RuntimeException('Cannot open output synchronization lock');
        }
        $this->lock = $lock;
    }

    public function write(string $text, bool $error = false): void
    {
        $group = $this->current();
        if ($group === null || $group->paused) {
            StreamOutput::write($error ? STDERR : STDOUT, $text);
            return;
        }
        $stream = $error ? $group->err : $group->out;
        fseek($stream, 0, SEEK_END);
        StreamOutput::write($stream, $text);
    }

    private function current(): ?OutputGroup
    {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? $this->root : $this->groups[$fiber] ?? null;
    }

    private function flush(OutputGroup $group): void
    {
        if (
            ($size = fstat($group->out)) !== false
            && $size['size'] === 0
            && ($size = fstat($group->err)) !== false
            && $size['size'] === 0
        ) {
            return;
        }
        $this->prepare();
        if ($this->lock !== null) {
            flock($this->lock, LOCK_EX);
        }
        try {
            // Recursive makes share an open file description; refresh PHP's cached position.
            if (stream_get_meta_data(STDOUT)['seekable']) {
                fseek(STDOUT, 0, SEEK_END);
            }
            if (stream_get_meta_data(STDERR)['seekable']) {
                fseek(STDERR, 0, SEEK_END);
            }
            if ($this->directory !== null) {
                StreamOutput::write(
                    STDOUT,
                    $this->directory[0] . ": Entering directory '" . $this->directory[1] . "'\n",
                );
            }
            rewind($group->out);
            StreamOutput::copy($group->out, STDOUT);
            if (!$group->combined) {
                rewind($group->err);
                StreamOutput::copy($group->err, STDERR);
                ftruncate($group->err, 0);
                rewind($group->err);
            }
            ftruncate($group->out, 0);
            rewind($group->out);
            if ($this->directory !== null) {
                StreamOutput::write(
                    STDOUT,
                    $this->directory[0] . ": Leaving directory '" . $this->directory[1] . "'\n",
                );
            }
        } finally {
            if ($this->lock !== null) {
                flock($this->lock, LOCK_UN);
            }
        }
    }

    public function __destruct()
    {
        if ($this->lock !== null) {
            fclose($this->lock);
        }
        if ($this->owned !== null) {
            @unlink($this->owned);
        }
    }
}
