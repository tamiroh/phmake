<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function getenv;
use function str_starts_with;

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
            $path = new ExecutableFinder()->find($name);
            if ($path === null) {
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
}
