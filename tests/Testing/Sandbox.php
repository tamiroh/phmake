<?php

namespace Tamiroh\Phmake\Tests\Testing;

use RuntimeException;

final class Sandbox
{
    public string $uniqueId;

    public string $path;

    private function __construct()
    {
        $this->uniqueId = uniqid(more_entropy: true);
        $this->path = sys_get_temp_dir() . '/phmake-testing/' . $this->uniqueId;
    }

    public static function create(): self
    {
        $instance = new self();

        $mkdirResult = mkdir($instance->path, recursive: true);
        if (! $mkdirResult) {
            throw new RuntimeException('Failed to create sandbox directory');
        }

        return $instance;
    }

    public function placeMakefile(string $content): self
    {
        file_put_contents($this->path . '/Makefile', $content);

        return $this;
    }

    /**
     * @param list<array{name: string, content: string}> $files
     */
    public function placeFiles(array $files): self
    {
        foreach ($files as $file) {
            file_put_contents($this->path . '/' . $file['name'], $file['content']);
        }

        return $this;
    }

    public function runPhMake(string $arguments = ''): string
    {
        $phMakePath = realpath(__DIR__ . '/../../phmake');

        if (PHP_OS === 'Darwin') {
            putenv("PATH=/usr/local/bin:" . getenv("PATH"));
        }

        $output = shell_exec("cd $this->path && php $phMakePath $arguments");
        if ($output === false) {
            throw new RuntimeException('Failed to run phmake');
        }

        return $output === null ? '' : $output;
    }
}