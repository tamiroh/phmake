<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_filter;
use function array_slice;
use function array_values;
use function dirname;
use function implode;
use function preg_split;
use function rtrim;
use function str_starts_with;
use function substr;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Selective vpath entries are read in order; VPATH and GPATH use final global values.
 */
final class SearchPaths
{
    /** @var list<array{string, list<string>}> */
    private array $selective = [];

    /**
     * @return list<string>
     */
    private static function directories(string $text): array
    {
        $paths = preg_split('/[\s:]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $paths === false ? [] : $paths;
    }

    /**
     * @param list<string> $words
     */
    public function define(array $words): void
    {
        if ($words === []) {
            $this->selective = [];
        } elseif (isset($words[1])) {
            $this->selective[] = [$words[0], self::directories(implode(' ', array_slice($words, 1)))];
        } else {
            $this->selective = array_values(array_filter(
                $this->selective,
                static fn(array $entry): bool => $entry[0] !== $words[0],
            ));
        }
    }

    /** @param array<string, Target> $targets
     * @throws MakefileErrorException
     */
    public function find(string $name, Filesystem $filesystem, VariableExpander $expander, array $targets): ?string
    {
        if ($filesystem->exists($name)) {
            return $name;
        }
        if (!str_starts_with($name, '-l')) {
            return $this->search([$name], $filesystem, $expander, $targets);
        }
        $patterns = DependencySyntax::words($expander->expand('$(.LIBPATTERNS)'));
        $names = [];
        foreach ($patterns as $pattern) {
            if (!new Pattern($pattern)->hasWildcard()) {
                $expander->output?->writeWarning(".LIBPATTERNS element '$pattern' is not a pattern");
                continue;
            }
            $names[] = new Pattern($pattern)->substitute(substr($name, 2));
        }
        foreach ($names as $library) {
            if ($filesystem->exists($library) || isset($targets[$library])) {
                return $library;
            }
        }
        return $this->search($names, $filesystem, $expander, $targets);
    }

    /**
     * @throws MakefileErrorException
     */
    public function retain(string $path, VariableExpander $expander): bool
    {
        foreach (self::directories($expander->expand('$(GPATH)')) as $directory) {
            if (rtrim($directory, '/') === dirname($path)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $names
     * @param array<string, Target> $targets
     *
     * @throws MakefileErrorException
     */
    private function search(array $names, Filesystem $filesystem, VariableExpander $expander, array $targets): ?string
    {
        foreach ([...$this->selective, ['%', self::directories($expander->expand('$(VPATH)'))]] as [$pattern, $paths]) {
            foreach ($paths as $directory) {
                foreach ($names as $name) {
                    if (str_starts_with($name, '/') || new Pattern($pattern)->match($name) === null) {
                        continue;
                    }
                    $path = rtrim($directory, '/') . '/' . $name;
                    if ($filesystem->exists($path) || isset($targets[$path])) {
                        return $path;
                    }
                }
            }
        }
        return null;
    }
}
