<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\FakeShell;

final class TargetTest extends TestCase
{
    #[Test]
    public function runsDependencyBeforeOwnCommands(): void
    {
        $bar = new Target(name: 'bar', dependencies: [], startLineIndex: 0, endLineIndex: 1, commands: ['echo bar']);
        $foo = new Target(
            name: 'foo',
            dependencies: [$bar],
            startLineIndex: 2,
            endLineIndex: 3,
            commands: ['echo foo'],
        );

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: []);

        $rebuilt = $foo->run($shell, $filesystem, $output);

        $this->assertTrue($rebuilt);
        $this->assertSame(['echo bar', 'echo foo'], $shell->commands);
        $this->assertSame(['echo bar', 'echo foo'], $output->lines);
    }

    #[Test]
    public function doesNotRunCommandsWhenTargetIsUpToDate(): void
    {
        $bar = new Target(name: 'bar', dependencies: [], startLineIndex: 0, endLineIndex: 1, commands: ['echo bar']);
        $foo = new Target(
            name: 'foo',
            dependencies: [$bar],
            startLineIndex: 2,
            endLineIndex: 3,
            commands: ['echo foo'],
        );

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: [
            'bar' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
            'foo' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
        ]);

        $rebuilt = $foo->run($shell, $filesystem, $output);

        $this->assertFalse($rebuilt);
        $this->assertSame([], $shell->commands);
        $this->assertSame([], $output->lines);
    }

    #[Test]
    public function runsCommandsWhenDependencyIsNewerThanTarget(): void
    {
        $bar = new Target(name: 'bar', dependencies: [], startLineIndex: 0, endLineIndex: 1, commands: ['echo bar']);
        $foo = new Target(
            name: 'foo',
            dependencies: [$bar],
            startLineIndex: 2,
            endLineIndex: 3,
            commands: ['echo foo'],
        );

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: [
            'bar' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:01:00')],
            'foo' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
        ]);

        $rebuilt = $foo->run($shell, $filesystem, $output);

        $this->assertTrue($rebuilt);
        $this->assertSame(['echo foo'], $shell->commands);
        $this->assertSame(['echo foo'], $output->lines);
    }

    #[Test]
    public function stopsWhenACommandFails(): void
    {
        $foo = new Target(
            name: 'foo',
            dependencies: [],
            startLineIndex: 0,
            endLineIndex: 2,
            commands: ['false', 'echo foo'],
        );

        $shell = new FakeShell();
        $shell->exitCodes['false'] = 1;

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessage('[foo] Error 1');

        try {
            $foo->run(
                $shell,
                new FakeFilesystem(files: []),
                new FakeOutput(),
            );
        } finally {
            $this->assertSame(['false'], $shell->commands);
        }
    }
}
