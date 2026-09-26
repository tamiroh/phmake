<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Override;
use Random\RandomException;
use Symfony\Component\Process\Process;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Makefile\Execution\ParallelOptions;
use Tamiroh\Phmake\Makefile\IO\JobSlots;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function bin2hex;
use function fclose;
use function floatval;
use function fopen;
use function fread;
use function fwrite;
use function getenv;
use function is_resource;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function str_starts_with;
use function stream_set_blocking;
use function stream_socket_pair;
use function substr;
use function sys_get_temp_dir;
use function sys_getloadavg;
use function unlink;

use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * GNU jobserver tokens, with one implicit slot per make invocation.
 */
final class Jobserver implements JobSlots
{
    /** @var resource|null */
    private mixed $reader = null;

    /** @var resource|null */
    private mixed $writer = null;

    private ?string $directory = null;

    private bool $implicit = false;

    private int $active = 0;

    /**
     * @throws MakefileErrorException
     */
    public function __construct(
        private readonly ParallelOptions $options,
        Output $output,
    ) {
        if ($options->reset) {
            $output->writeWarning(
                'warning: -j' . $options->jobs . ' forced in submake: resetting jobserver mode.',
                $output->prefix,
            );
        }
        if ($options->jobs <= 1) {
            $options->auth = null;
            return;
        }
        if ($options->auth !== null) {
            if ($this->adopt($options->auth, $output)) {
                return;
            }
            $output->writeWarning(
                "warning: jobserver unavailable: using -j1.  Add '+' to parent make rule.",
                $output->prefix,
            );
            $options->jobs = 1;
            $options->auth = null;
            return;
        }
        if ($options->style === 'pipe') {
            $this->pipe();
        } else {
            $this->fifo($output);
        }
        if (
            $this->writer === null
            || fwrite($this->writer, str_repeat('+', $options->jobs - 1)) !== ($options->jobs - 1)
        ) {
            throw new MakefileErrorException('Cannot initialize jobserver tokens');
        }
    }

    #[Override]
    public function acquire(): ?string
    {
        $load = $this->options->load === null ? false : sys_getloadavg();
        if (
            $this->active > 0
            && $this->options->load !== null
            && $load !== false
            && floatval($load[0]) >= $this->options->load
        ) {
            return null;
        }
        if ($this->options->jobs === 0) {
            $this->active++;
            return '';
        }
        if (!$this->implicit) {
            $this->implicit = true;
            $this->active++;
            return '';
        }
        if ($this->reader === null) {
            return null;
        }
        $slot = fread($this->reader, 1);
        if ($slot === false || $slot === '') {
            return null;
        }
        $this->active++;
        return $slot;
    }

    /**
     * @return array{3?: resource, 4?: resource}
     */
    public function descriptors(): array
    {
        return (
            $this->options->auth === '3,4' && $this->reader !== null && $this->writer !== null
                ? [3 => $this->reader, 4 => $this->writer]
                : []
        );
    }

    #[Override]
    public function release(string $slot): void
    {
        $this->active--;
        if ($slot === '') {
            $this->implicit = false;
        } elseif ($this->writer !== null) {
            fwrite($this->writer, $slot);
        }
    }

    private function adopt(string $auth, Output $output): bool
    {
        if (str_starts_with($auth, 'fifo:')) {
            $path = substr($auth, 5);
            $reader = @fopen($path, 'r+');
            if (!is_resource($reader)) {
                $output->writeWarning("cannot open jobserver $path: No such file or directory", $output->prefix);
                return false;
            }
            $this->reader = $this->writer = $reader;
        } elseif (preg_match('/^([0-9]+),([0-9]+)$/D', $auth, $matches) === 1) {
            $reader = @fopen('php://fd/' . $matches[1], 'r');
            $writer = @fopen('php://fd/' . $matches[2], 'w');
            if (!is_resource($reader) || !is_resource($writer)) {
                if (is_resource($reader)) {
                    fclose($reader);
                }
                if (is_resource($writer)) {
                    fclose($writer);
                }
                return false;
            }
            $this->reader = $reader;
            $this->writer = $writer;
            $this->options->auth = '3,4';
        } else {
            return false;
        }
        stream_set_blocking($this->reader, false);
        return true;
    }

    /**
     * @throws MakefileErrorException
     */
    private function fifo(Output $output): void
    {
        try {
            $base = getenv('MAKE_TMPDIR');
            $this->directory =
                ($base === false || $base === '' ? sys_get_temp_dir() : $base)
                . '/phmake-jobserver-'
                . bin2hex(random_bytes(8));
        } catch (RandomException $error) {
            throw new MakefileErrorException($error->getMessage());
        }
        if (!@mkdir($this->directory, 0700)) {
            $output->writeWarning('cannot create jobserver: No such file or directory', $output->prefix);
            $this->directory = null;
            $this->pipe();
            return;
        }
        $path = $this->directory . '/fifo';
        if (new Process(['mkfifo', '-m', '600', $path])->run() !== 0) {
            $this->pipe();
            return;
        }
        $stream = fopen($path, 'r+');
        if (!is_resource($stream)) {
            throw new MakefileErrorException('Cannot open jobserver FIFO');
        }
        $this->reader = $this->writer = $stream;
        stream_set_blocking($stream, false);
        $this->options->auth = 'fifo:' . $path;
    }

    /**
     * @throws MakefileErrorException
     */
    private function pipe(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || !isset($pair[0], $pair[1])) {
            throw new MakefileErrorException('Cannot create jobserver pipe');
        }
        [$this->reader, $this->writer] = $pair;
        stream_set_blocking($this->reader, false);
        $this->options->auth = '3,4';
    }

    public function __destruct()
    {
        if ($this->writer !== null && $this->writer !== $this->reader) {
            fclose($this->writer);
        }
        if ($this->reader !== null) {
            fclose($this->reader);
        }
        if ($this->directory !== null) {
            @unlink($this->directory . '/fifo');
            @rmdir($this->directory);
        }
    }
}
