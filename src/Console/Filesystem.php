<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Filesystem as FilesystemInterface;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function clearstatcache;
use function error_get_last;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function getcwd;
use function glob;
use function is_dir;
use function preg_replace;
use function realpath;
use function str_starts_with;
use function ucfirst;
use function unlink;

final class Filesystem implements FilesystemInterface
{
    #[\Override]
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    #[\Override]
    public function lastModified(string $path): ?int
    {
        clearstatcache(true, $path);
        $result = @filemtime($path);

        return $result === false ? null : $result;
    }

    /** @return list<string> */
    #[\Override]
    public function matching(string $pattern): array
    {
        $paths = glob($pattern);
        return $paths === false ? [] : $paths;
    }

    /** @throws MakefileErrorException */
    #[\Override]
    public function read(string $path): ?string
    {
        clearstatcache(true, $path);
        if (is_dir($path)) {
            throw new MakefileErrorException('read: ' . $path . ': Is a directory');
        }
        if (!file_exists($path)) {
            return null;
        }
        $text = @file_get_contents(str_starts_with($path, '/') ? $path : './' . $path);
        if ($text === false) {
            throw $this->failure('open', $path);
        }
        return $text;
    }

    #[\Override]
    public function realpath(string $path): ?string
    {
        clearstatcache(true, $path);
        $resolved = realpath($path);
        return $resolved === false ? null : $resolved;
    }

    #[\Override]
    public function remove(string $path): bool
    {
        return @unlink($path);
    }

    #[\Override]
    public function workingDirectory(): string
    {
        return (string) getcwd();
    }

    /** @throws MakefileErrorException */
    #[\Override]
    public function write(string $path, string $text, bool $append): void
    {
        if (
            @file_put_contents(str_starts_with($path, '/') ? $path : './' . $path, $text, $append ? FILE_APPEND : 0)
            === false
        ) {
            throw $this->failure('open', $path);
        }
        clearstatcache(true, $path);
    }

    private function failure(string $operation, string $path): MakefileErrorException
    {
        return new MakefileErrorException(
            $operation . ': ' . $path . ': '
                . ucfirst(
                    preg_replace('/^.*Failed to open stream: /', '', error_get_last()['message'] ?? 'I/O error')
                    ?? 'I/O error',
                ),
        );
    }
}
