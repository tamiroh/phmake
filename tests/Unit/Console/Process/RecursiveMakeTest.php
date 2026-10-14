<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Console\Process;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Console\Process\RecursiveMake;

use function array_map;
use function escapeshellarg;
use function implode;

final class RecursiveMakeTest extends TestCase
{
    private static function make(): string
    {
        return implode(' ', array_map(escapeshellarg(...), RecursiveMake::program()));
    }

    #[Test]
    public function leavesShellSyntaxToTheShell(): void
    {
        foreach ([
            self::make() . ' $(TARGET)',
            self::make() . ' "$HOME"',
            self::make() . ' all; echo done',
            self::make() . ' all | tee log',
            self::make() . ' *.mk',
            self::make() . ' ~/target',
            'cd src; ' . self::make(),
            'cd -P src && ' . self::make(),
            'cd link/.. && ' . self::make(),
            'cd ../lib && ' . self::make(),
            'cd src && cd lib && ' . self::make(),
            'VALUE=1 ' . self::make(),
            'make all',
        ] as $command) {
            self::assertNull(RecursiveMake::parse($command, [], '/bin/sh', '-c'), $command);
        }
        self::assertNull(RecursiveMake::parse(self::make(), [], '/bin/bash', '-c'));
        self::assertNull(RecursiveMake::parse('cd src && ' . self::make(), ['CDPATH' => '/tmp'], '/bin/sh', '-c'));
    }

    #[Test]
    public function readsQuotedArguments(): void
    {
        self::assertSame(
            [null, ['all', 'SYSCFLAGS=-DLUA_USE_LINUX', 'SYSLIBS=-Wl,-E -ldl', "it's", 'a\\b']],
            RecursiveMake::parse(
                self::make() . ' all SYSCFLAGS="-DLUA_USE_LINUX" SYSLIBS="-Wl,-E -ldl" it\\\'s "a\\\\b"',
                [],
                '/bin/sh',
                '-c',
            ),
        );
    }

    #[Test]
    public function readsTheDirectoryEnteredFirst(): void
    {
        self::assertSame(
            ['src', ['linux']],
            RecursiveMake::parse('cd src && ' . self::make() . ' linux', [], '/bin/sh', '-ec'),
        );
    }
}
