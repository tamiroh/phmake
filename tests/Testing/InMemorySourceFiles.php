<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Override;
use Tamiroh\Phmake\Makefile\IO\SourceFiles;
use Tamiroh\Phmake\Makefile\IO\SourceText;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function fnmatch;
use function in_array;
use function sort;

final readonly class InMemorySourceFiles implements SourceFiles
{
    /**
     * @param array<string, string> $files
     * @param list<string> $directories
     */
    public function __construct(
        private array $files,
        private array $directories = [],
    ) {}

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
            $times[$path] = $this->read($path)->modifiedAt;
        }
        return $times;
    }

    #[Override]
    public function read(string $path): SourceText
    {
        return array_key_exists($path, $this->files)
            ? new SourceText($this->files[$path], modifiedAt: '1')
            : new SourceText(null, 'No such file or directory');
    }

    #[Override]
    public function readMany(array $paths): iterable
    {
        foreach ($paths as $path) {
            yield $path => $this->read($path);
        }
    }
}
