<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Console\Process;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Console\Process\CapturedProcess;
use Tamiroh\Phmake\Console\Process\Signals;

use function getenv;
use function putenv;
use function str_repeat;

use const PHP_BINARY;
use const SIGTERM;

final class CapturedProcessTest extends TestCase
{
    #[Test]
    public function acceptsShellCommands(): void
    {
        $result = CapturedProcess::run('printf first; printf second; exit 3');
        self::assertSame('firstsecond', $result->output);
        self::assertSame(3, $result->status);
    }

    #[Test]
    public function closesInputAndPreservesLiteralArguments(): void
    {
        $result = CapturedProcess::run([
            PHP_BINARY,
            '-r',
            'echo stream_get_contents(STDIN) === "" ? $argv[1] : "unexpected input";',
            'spaces $HOME; "quoted"',
        ]);
        self::assertSame('spaces $HOME; "quoted"', $result->output);
        self::assertSame(0, $result->status);
    }

    #[Test]
    public function drainsBothStreamsBeyondPipeCapacity(): void
    {
        $stderr = '';
        $result = CapturedProcess::run([PHP_BINARY, '-r', <<<'PHP'
                for ($i = 0; $i < 128; $i++) {
                    fwrite(STDOUT, str_repeat('o', 8192));
                    fwrite(STDERR, str_repeat('e', 8192));
                }
                exit(7);
                PHP], stderr: static function (string $buffer) use (&$stderr): void {
            $stderr .= $buffer;
        });
        self::assertSame(str_repeat('o', 1_048_576), $result->output);
        self::assertSame(str_repeat('e', 1_048_576), $stderr);
        self::assertSame(7, $result->status);
    }

    #[Test]
    public function forwardsAnInterruptionWhileCapturing(): void
    {
        $previous = Signals::$received;
        try {
            Signals::$received = 0;
            $result = CapturedProcess::run([
                'sh',
                '-c',
                'printf ready >&2; exec sleep 30',
            ], stderr: static function (string $buffer): void {
                if ($buffer !== '') {
                    Signals::$received = SIGTERM;
                }
            });
            self::assertSame('', $result->output);
            self::assertSame(143, $result->status);
        } finally {
            Signals::$received = $previous;
        }
    }

    #[Test]
    public function preservesEmptyAndRemovedEnvironmentValuesAndRestoresTheParent(): void
    {
        $previous = getenv('PHMAKE_PROCESS_TEST_VALUE');
        try {
            putenv('PHMAKE_PROCESS_TEST_VALUE=parent');
            foreach (['' => 'empty', 'child' => 'child'] as $value => $expected) {
                $result = CapturedProcess::run([
                    PHP_BINARY,
                    '-r',
                    'echo getenv("PHMAKE_PROCESS_TEST_VALUE") === "" ? "empty" : getenv("PHMAKE_PROCESS_TEST_VALUE");',
                ], ['PHMAKE_PROCESS_TEST_VALUE' => $value]);
                self::assertSame($expected, $result->output);
                self::assertSame(0, $result->status);
                self::assertSame('parent', getenv('PHMAKE_PROCESS_TEST_VALUE'));
            }
            $result = CapturedProcess::run([
                PHP_BINARY,
                '-r',
                'echo getenv("PHMAKE_PROCESS_TEST_VALUE") === false ? "removed" : "present";',
            ], ['PHMAKE_PROCESS_TEST_VALUE' => false]);
            self::assertSame('removed', $result->output);
            self::assertSame('parent', getenv('PHMAKE_PROCESS_TEST_VALUE'));
        } finally {
            putenv($previous === false ? 'PHMAKE_PROCESS_TEST_VALUE' : 'PHMAKE_PROCESS_TEST_VALUE=' . $previous);
        }
    }

    #[Test]
    public function reportsSignalExitStatusAfterDrainingOutput(): void
    {
        $result = CapturedProcess::run(['sh', '-c', 'printf before; (sleep 0.02; printf after) & kill -TERM $$']);
        self::assertSame('beforeafter', $result->output);
        self::assertSame(143, $result->status);
    }
}
