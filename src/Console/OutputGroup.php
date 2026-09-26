<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use RuntimeException;

use function fclose;
use function fstat;
use function tmpfile;

use const STDERR;
use const STDOUT;

/**
 * Temporary streams shared by one recipe and its child processes.
 */
final class OutputGroup
{
    /** @var resource */
    public readonly mixed $out;

    /** @var resource */
    public readonly mixed $err;

    public bool $paused = false;

    public readonly bool $combined;

    public function __construct()
    {
        $out = tmpfile();
        if ($out === false) {
            throw new RuntimeException('Cannot buffer recipe output');
        }
        $this->out = $out;
        $stdout = fstat(STDOUT);
        $stderr = fstat(STDERR);
        $this->combined =
            $stdout !== false
            && $stderr !== false
            && $stdout['dev'] === $stderr['dev']
            && $stdout['ino'] === $stderr['ino'];
        $err = $this->combined ? $out : tmpfile();
        if ($err === false) {
            throw new RuntimeException('Cannot buffer recipe error output');
        }
        $this->err = $err;
    }

    public function __destruct()
    {
        if (!$this->combined) {
            fclose($this->err);
        }
        fclose($this->out);
    }
}
