<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tamiroh\Phmake\Tests\Testing\GnuMake;
use Tamiroh\Phmake\Tests\Testing\Sandbox;

use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function is_file;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;
use function trim;

final class E2ETest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSessions(): iterable
    {
        $snapshots = glob(__DIR__ . '/fixtures/*/session.txt');
        if ($snapshots === false || $snapshots === []) {
            throw new RuntimeException('No E2E snapshots found');
        }
        foreach ($snapshots as $snapshot) {
            yield basename(dirname($snapshot)) => [dirname($snapshot)];
        }
    }

    #[Test]
    #[DataProvider('provideSessions')]
    public function gnuMakeMatchesCommandSnapshot(string $fixtureDirectory): void
    {
        if (is_file($fixtureDirectory . '/gnu-version.txt')) {
            $minimum = file_get_contents($fixtureDirectory . '/gnu-version.txt');
            self::assertIsString($minimum);
            if (!GnuMake::supports(trim($minimum))) {
                self::markTestSkipped('Reference GNU make requires version ' . trim($minimum));
            }
        }
        $expected = $this->readSnapshot($fixtureDirectory);
        $executable = GnuMake::executable();
        $actual = $this->runSession($fixtureDirectory, $expected, $executable);

        self::assertSame(
            $this->normalizeOutput($expected, basename($executable)),
            $this->normalizeOutput($actual, basename($executable)),
        );
    }

    #[Test]
    #[DataProvider('provideSessions')]
    public function matchesCommandSnapshot(string $fixtureDirectory): void
    {
        $expected = $this->readSnapshot($fixtureDirectory);

        self::assertSame($expected, $this->runSession($fixtureDirectory, $expected));
    }

    private function normalizeOutput(string $output, string $programName): string
    {
        // GNU make versions differ in diagnostic quotes and recipe source locations.
        // Keep command output and exit-status markers unchanged.
        return preg_replace_callback(
            '/^(?:' . preg_quote($programName, delimiter: '/') . '|phmake): ([^\n]*)$/m',
            static function (array $matches): string {
                /** @var array{string, string} $matches */
                $message = str_replace('`', replace: "'", subject: $matches[1]);
                $message =
                    preg_replace(
                        '/^(\*\*\* \[)Makefile:[0-9]+: (.*\] Error [0-9]+)$/',
                        replacement: '$1$2',
                        subject: $message,
                    ) ?? $message;
                return 'phmake: ' . $message;
            },
            $output,
        ) ?? $output;
    }

    private function readSnapshot(string $fixtureDirectory): string
    {
        $expected = file_get_contents($fixtureDirectory . '/session.txt');
        self::assertIsString($expected);
        self::assertStringStartsWith('$ ', $expected);

        return $expected;
    }

    private function runSession(string $fixtureDirectory, string $session, ?string $executable = null): string
    {
        $commands = [];
        preg_match_all('/^\$ (.+)$/m', $session, $commands);
        $sandbox = Sandbox::create($fixtureDirectory, $executable);

        try {
            $actual = '';
            foreach ($commands[1] as $command) {
                $actual .= '$ ' . $command . "\n" . $sandbox->runCommand($command);
            }
            return $actual;
        } finally {
            $sandbox->remove();
        }
    }
}
