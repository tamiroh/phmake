<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tamiroh\Phmake\Tests\Testing\Sandbox;

final class E2ETest extends TestCase
{
    #[Test]
    #[DataProvider('provideSessions')]
    public function matchesCommandSnapshot(string $fixtureDirectory): void
    {
        $expected = file_get_contents($fixtureDirectory . '/session.txt');
        self::assertIsString($expected);
        self::assertStringStartsWith('$ ', $expected);
        preg_match_all('/^\$ (.+)$/m', $expected, $commands);
        $sandbox = Sandbox::create($fixtureDirectory);

        try {
            $actual = '';
            foreach ($commands[1] as $command) {
                $actual .= '$ ' . $command . "\n" . $sandbox->runCommand($command);
            }
            self::assertSame($expected, $actual);
        } finally {
            $sandbox->remove();
        }
    }

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
}
