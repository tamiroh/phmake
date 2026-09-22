<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

use function chmod;
use function copy;
use function dirname;
use function escapeshellarg;
use function file_put_contents;
use function getenv;
use function mkdir;
use function rmdir;
use function str_ends_with;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final readonly class Sandbox
{
    private function __construct(
        private string $path,
    ) {}

    public static function create(string $fixtureDirectory, ?string $executable = null): self
    {
        $sandbox = new self(sys_get_temp_dir() . '/phmake-testing/' . uniqid(more_entropy: true));
        if (!mkdir($sandbox->path, recursive: true)) {
            throw new RuntimeException('Failed to create sandbox directory');
        }

        try {
            /** @var SplFileInfo $file */
            foreach (new FilesystemIterator($fixtureDirectory) as $file) {
                if ($file->getFilename() === 'session.txt') {
                    continue;
                }
                if (!copy($file->getPathname(), $sandbox->path . '/' . $file->getFilename())) {
                    throw new RuntimeException('Failed to copy fixture');
                }
            }
            if ($executable === null) {
                if (!symlink(__DIR__ . '/../../phmake', $sandbox->path . '/phmake')) {
                    throw new RuntimeException('Failed to link phmake');
                }
            } else {
                if (
                    file_put_contents(
                        $sandbox->path . '/phmake',
                        "#!/bin/sh\nexec " . escapeshellarg($executable) . ' "$@"' . "\n",
                    ) === false
                    || !chmod($sandbox->path . '/phmake', permissions: 0755)
                ) {
                    throw new RuntimeException('Failed to create GNU make launcher');
                }
            }

            return $sandbox;
        } catch (Throwable $error) {
            $sandbox->remove();
            throw new RuntimeException('Failed to initialize sandbox', previous: $error);
        }
    }

    public function runCommand(string $command): string
    {
        $process = new Process(['sh', '-c', "exec 2>&1\n" . $command], $this->path, [
            'PATH' => dirname(PHP_BINARY) . PATH_SEPARATOR . (string) getenv('PATH'),
            'LC_ALL' => 'C',
            'TERM' => 'dumb',
            'NO_COLOR' => '1',
            'MAKEFLAGS' => false,
            'GNUMAKEFLAGS' => false,
            'MFLAGS' => false,
            'MAKELEVEL' => false,
            'MAKEFILES' => false,
        ]);
        $exitCode = $process->run();
        $output = $process->getOutput();
        if ($output !== '' && !str_ends_with($output, "\n")) {
            $output .= "\n[no newline]\n";
        }
        if ($exitCode !== 0) {
            $output .= "[exit $exitCode]\n";
        }

        return $output;
    }

    public function remove(): void
    {
        self::removeDirectory($this->path);
    }

    private static function removeDirectory(string $path): void
    {
        /** @var SplFileInfo $file */
        foreach (new FilesystemIterator($path) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                self::removeDirectory($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($path);
    }
}
