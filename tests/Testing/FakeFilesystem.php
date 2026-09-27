<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Override;
use Tamiroh\Phmake\Makefile\IO\Filesystem;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function fnmatch;

final class FakeFilesystem implements Filesystem
{
    /**
     * @param array<string, string> $files
     */
    public function __construct(
        private array $files = [],
    ) {}

    #[Override]
    public function exists(string $path): bool
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
        return array_values(array_filter(array_keys($this->files), static fn(string $path): bool => fnmatch(
            $pattern,
            $path,
        )));
    }

    /**
     * @throws void
     */
    #[Override]
    public function read(string $path): ?string
    {
        return $this->files[$path] ?? null;
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
