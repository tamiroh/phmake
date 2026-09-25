<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\IO\Filesystem;

use function in_array;
use function rtrim;
use function str_starts_with;

/**
 * Input discovery and the ordered record of files read in one parsing pass.
 */
final class MakefileSources
{
    /** @var list<ReadFile> */
    public array $read = [];

    public bool $defaultGoal = true;

    /**
     * @param list<string> $main
     * @param list<string> $evaluations
     */
    public function __construct(
        public readonly SourceFiles $files,
        private readonly Filesystem $filesystem,
        public readonly array $main,
        private readonly ?Configuration $configuration = null,
        private readonly ?string $stdin = null,
        private readonly bool $optionalMain = false,
        public readonly array $evaluations = [],
    ) {}

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        $paths = [];
        $defaults = ['/usr/local/include', '/usr/include'];
        foreach ($this->configuration->includeDirectories ?? [] as $path) {
            if ($path === '-') {
                $paths = [];
                $defaults = [];
            } else {
                $paths[] = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
            }
        }
        $result = [];
        foreach ([...$paths, ...$defaults] as $path) {
            if ($this->files->isDirectory($path) && !in_array($path, $result, true)) {
                $result[] = $path;
            }
        }
        return $result;
    }

    /**
     * @return list<string>
     */
    public function matching(string $pattern): array
    {
        $paths = $this->files->matching($pattern);
        return $paths === [] ? [$pattern] : $paths;
    }

    public function open(
        string $name,
        bool $optional = false,
        bool $defaultGoal = true,
        ?string $source = null,
        bool $main = false,
    ): ReadFile {
        if ($main && $name === '-') {
            return $this->read[] = new ReadFile($name, $this->stdin, null, defaultGoal: $defaultGoal, rebuild: false);
        }
        $path = $name;
        $contents = $this->files->read($path);
        if (!$main && $contents->text === null && !$this->filesystem->exists($path) && !str_starts_with($name, '/')) {
            foreach ($this->directories() as $directory) {
                if (($found = $this->files->read($directory . '/' . $name))->text !== null) {
                    $path = $directory . '/' . $name;
                    $contents = $found;
                    break;
                }
            }
        }
        return $this->read[] = new ReadFile(
            $path,
            $contents->text,
            $contents->modifiedAt,
            $optional || $main && $this->optionalMain,
            $defaultGoal,
            $source,
            error: $contents->error,
        );
    }
}
