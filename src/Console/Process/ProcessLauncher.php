<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use function getenv;
use function proc_open;
use function putenv;

final class ProcessLauncher
{
    /**
     * Preserve empty environment values, which associative proc_open environments omit.
     * No fiber can run while the process environment is temporarily changed.
     *
     * @param non-empty-list<string>|string $command
     * @param array<array-key, string|false> $environment
     * @param array{0?: resource|array{string, string}, 1?: resource|array{string, string}, 2?: resource|array{string, string}, 3?: resource, 4?: resource} $descriptors
     * @param array<int, resource> $pipes
     *
     * @param-out array<int, resource> $pipes
     *
     * @return resource|false
     */
    public static function start(array|string $command, array $environment, array $descriptors, array &$pipes): mixed
    {
        $previous = [];
        try {
            foreach ($environment as $name => $value) {
                $previous[$name] = getenv((string) $name);
                putenv($value === false ? (string) $name : $name . '=' . $value);
            }
            // PHP supports arbitrary descriptor numbers; Mago's stub only lists 0-2.
            // https://www.php.net/manual/en/function.proc-open.php
            // @mago-expect analysis:possibly-invalid-argument
            return @proc_open($command, $descriptors, $pipes);
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? (string) $name : $name . '=' . $value);
            }
        }
    }
}
