<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class Sandbox
{
    private function __construct(
        private string $path,
    ) {}

    public static function create(string $fixtureDirectory): self
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
            if (!symlink(__DIR__ . '/../../phmake', $sandbox->path . '/phmake')) {
                throw new RuntimeException('Failed to link phmake');
            }

            return $sandbox;
        } catch (Throwable $error) {
            $sandbox->remove();
            throw $error;
        }
    }

    public function runCommand(string $command): string
    {
        $process = new Process(['sh', '-c', "exec 2>&1\n" . $command], $this->path, [
            'PATH' => dirname(PHP_BINARY) . PATH_SEPARATOR . (string) getenv('PATH'),
            'LC_ALL' => 'C',
            'TERM' => 'dumb',
            'NO_COLOR' => '1',
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
