<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\E2E;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class FormatCommandTest extends TestCase
{
    #[Test]
    public function checkReportsFormattingWithoutWriting(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'phmake-format-');
        self::assertIsString($file);
        try {
            file_put_contents($file, "NAME=value\n");
            $format = dirname(__DIR__, 2) . '/phmake';
            $check = new Process([$format, '--format', '--check', $file]);
            self::assertSame(1, $check->run());
            self::assertStringContainsString($file, $check->getOutput());
            self::assertSame("NAME=value\n", file_get_contents($file));

            self::assertSame(0, new Process([$format, '--format', $file])->run());
            self::assertSame("NAME = value\n", file_get_contents($file));
            self::assertSame(0, $check->run());
            self::assertSame('', $check->getOutput());
        } finally {
            unlink($file);
        }
    }
}
