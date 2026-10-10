<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Override;
use Tamiroh\Phmake\Engine\IO\Filesystem;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function fnmatch;
use function in_array;
use function sort;

final class FakeFilesystem implements Filesystem
{
    /**
     * @param array<string, string> $files
     * @param list<string> $directories
     */
    public function __construct(
        private array $files = [],
        private array $directories = [],
    ) {}

    #[Override]
    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    #[Override]
    public function isDirectory(string $path): bool
    {
        return in_array($path, $this->directories, strict: true);
    }

    #[Override]
    public function isFile(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    #[Override]
    public function lastModified(string $path): ?int
    {
        return array_key_exists($path, $this->files) ? 1 : null;
    }

    #[Override]
    public function matching(string $pattern): array
    {
        $paths = array_values(array_filter(array_keys($this->files), static fn(string $path): bool => fnmatch(
            $pattern,
            $path,
        )));
        sort($paths);
        return $paths;
    }

    #[Override]
    public function modifiedTimes(array $paths): array
    {
        $times = [];
        foreach ($paths as $path) {
            $times[$path] = $this->read($path)['modifiedAt'];
        }
        return $times;
    }

    /**
     * @return array{text: ?string, error: ?string, modifiedAt: ?string}
     */
    #[Override]
    public function read(string $path): array
    {
        return (
            array_key_exists($path, $this->files)
                ? ['text' => $this->files[$path], 'error' => null, 'modifiedAt' => '1']
                : ['text' => null, 'error' => 'No such file or directory', 'modifiedAt' => null]
        );
    }

    #[Override]
    public function realpath(string $path): ?string
    {
        return array_key_exists($path, $this->files) ? '/work/' . $path : null;
    }

    #[Override]
    public function remove(string $path): bool
    {
        $existed = array_key_exists($path, $this->files);
        unset($this->files[$path]);
        return $existed;
    }

    /**
     * @throws void
     */
    #[Override]
    public function touch(string $path): void
    {
        $this->files[$path] ??= '';
    }

    #[Override]
    public function workingDirectory(): string
    {
        return '/work';
    }

    /**
     * @throws void
     */
    #[Override]
    public function write(string $path, string $text, bool $append): void
    {
        $this->files[$path] = ($append ? $this->files[$path] ?? '' : '') . $text;
    }
}
