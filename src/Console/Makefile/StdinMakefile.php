<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Makefile;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_writable;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Keep standard input available while included makefiles are remade.
 */
final readonly class StdinMakefile
{
    public string $path;

    public string $text;

    /**
     * @throws MakefileErrorException
     */
    public function __construct(?string $path = null)
    {
        if ($path !== null) {
            $text = @file_get_contents($path);
            if ($text === false) {
                throw new MakefileErrorException('cannot read makefile from stdin temporary file');
            }
            $this->path = $path;
            $this->text = $text;
            return;
        }
        $directory = getenv('TMPDIR');
        $directory = $directory === false || $directory === '' ? sys_get_temp_dir() : $directory;
        $path = is_writable($directory) ? @tempnam($directory, 'Gm') : false;
        if ($path === false) {
            throw new MakefileErrorException('cannot store makefile from stdin to a temporary file');
        }
        $this->path = $path;
        $text = file_get_contents('php://stdin');
        if ($text === false || @file_put_contents($path, $text) === false) {
            @unlink($path);
            throw new MakefileErrorException('cannot store makefile from stdin to a temporary file');
        }
        $this->text = $text;
    }

    public function __destruct()
    {
        @unlink($this->path);
    }
}
