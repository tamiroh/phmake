<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function explode;
use function getenv;
use function is_executable;
use function realpath;
use function str_contains;
use function str_starts_with;
use function substr;
use function version_compare;

final class GnuMake
{
    private static ?string $executable = null;

    public static function executable(): string
    {
        if (self::$executable !== null) {
            return self::$executable;
        }

        $configured = getenv('GNU_MAKE');
        foreach ($configured === false || $configured === '' ? ['gmake', 'make'] : [$configured] as $name) {
            $path = str_contains($name, '/') ? realpath($name) : new ExecutableFinder()->find($name);
            if ($path === null || $path === false || !is_executable($path)) {
                continue;
            }
            $process = new Process([$path, '--version'], env: ['LC_ALL' => 'C']);
            if ($process->run() === 0 && str_starts_with($process->getOutput(), 'GNU Make ')) {
                return self::$executable = $path;
            }
        }

        throw new RuntimeException(
            'GNU make is required for E2E tests. Install gmake/make or set GNU_MAKE to its executable.',
        );
    }

    public static function supports(string $minimum): bool
    {
        $process = new Process([self::executable(), '--version'], env: ['LC_ALL' => 'C']);
        $process->mustRun();
        return version_compare(substr(explode("\n", $process->getOutput())[0], 9), $minimum, '>=');
    }
}
