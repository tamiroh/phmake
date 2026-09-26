<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Filesystem;

use Override;
use Tamiroh\Phmake\Makefile\Execution\FileOptions;
use Tamiroh\Phmake\Makefile\IO\Filesystem as FilesystemInterface;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\ArchiveMember;

use function clearstatcache;
use function error_get_last;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function glob;
use function is_dir;
use function is_link;
use function preg_replace;
use function realpath;
use function str_starts_with;
use function touch;
use function ucfirst;
use function unlink;

use const FILE_APPEND;

final class Filesystem implements FilesystemInterface
{
    public ?FileOptions $options = null;

    #[Override]
    public function exists(string $path): bool
    {
        if (($member = ArchiveMember::parse($path)) !== null) {
            return Archive::modified($member) !== null;
        }
        return file_exists($path) || ($this->options->checkSymlinkTimes ?? false) && is_link($path);
    }

    #[Override]
    public function lastModified(string $path): ?int
    {
        if (($member = ArchiveMember::parse($path)) !== null) {
            return Archive::modified($member);
        }
        return FileTimes::modified($path, $this->options->checkSymlinkTimes ?? false);
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function matching(string $pattern): array
    {
        if (($member = ArchiveMember::parse($pattern)) !== null) {
            return Archive::matching($member);
        }
        $paths = glob($pattern);
        return $paths === false ? [] : $paths;
    }

    /**
     * @throws MakefileErrorException
     */
    #[Override]
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

    #[Override]
    public function realpath(string $path): ?string
    {
        clearstatcache(true, $path);
        $resolved = realpath($path);
        return $resolved === false ? null : $resolved;
    }

    #[Override]
    public function remove(string $path): bool
    {
        return @unlink($path);
    }

    /**
     * @throws MakefileErrorException
     */
    #[Override]
    public function touch(string $path): void
    {
        $member = ArchiveMember::parse($path);
        if (!($member === null ? @touch($path) : Archive::touch($member))) {
            throw $this->failure('touch', $path);
        }
        clearstatcache(true, $path);
    }

    #[Override]
    public function workingDirectory(): string
    {
        return (string) getcwd();
    }

    /**
     * @throws MakefileErrorException
     */
    #[Override]
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
