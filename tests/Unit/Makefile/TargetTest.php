<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Command;
use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\FakeShell;

final class TargetTest extends TestCase
{
    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function doesNotRunCommandsWhenTargetIsUpToDate(): void
    {
        $foo = new Target(name: 'foo', dependencies: ['bar'], commands: [new Command('echo foo')], isPhony: false);

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: [
            'bar' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
            'foo' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
        ]);

        $rebuilt = $foo->run($shell, $filesystem, $output);

        self::assertFalse($rebuilt);
        self::assertSame([], $shell->commands);
        self::assertSame([], $output->lines);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function runsCommandsWhenDependencyIsNewerThanTarget(): void
    {
        $foo = new Target(name: 'foo', dependencies: ['bar'], commands: [new Command('echo foo')], isPhony: false);

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: [
            'bar' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:01:00')],
            'foo' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
        ]);

        $rebuilt = $foo->run($shell, $filesystem, $output);

        self::assertTrue($rebuilt);
        self::assertSame(['echo foo'], $shell->commands);
        self::assertSame(['echo foo'], $output->lines);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function stopsWhenACommandFails(): void
    {
        $foo = new Target(
            name: 'foo',
            dependencies: [],
            commands: [new Command('false'), new Command('echo foo')],
            isPhony: false,
        );

        $shell = new FakeShell();
        $shell->exitCodes['false'] = 1;

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessageIsOrContains('[foo] Error 1');

        try {
            $foo->run($shell, new FakeFilesystem(files: []), new FakeOutput());
        } finally {
            self::assertSame(['false'], $shell->commands);
        }
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function runsCommandsForPhonyTargetsEvenWhenTheFileExists(): void
    {
        $foo = new Target(name: 'foo', dependencies: [], commands: [new Command('echo foo')], isPhony: true);

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: [
            'foo' => ['modifiedAt' => new \DateTimeImmutable('2026-04-05 10:00:00')],
        ]);

        $rebuilt = $foo->run($shell, $filesystem, $output);

        self::assertTrue($rebuilt);
        self::assertSame(['echo foo'], $shell->commands);
        self::assertSame(['echo foo'], $output->lines);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function runsCommandsWhenADependencyWasRebuiltDespiteUnchangedTimestamps(): void
    {
        $shell = new FakeShell();
        $target = new Target('output', ['input'], [new Command('echo rebuild')], false);
        $filesystem = new FakeFilesystem(files: [
            'input' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
            'output' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:01:00')],
        ]);

        self::assertTrue($target->run($shell, $filesystem, new FakeOutput(), dependenciesRebuilt: true));
        self::assertSame(['echo rebuild'], $shell->commands);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function runsCommandsWhenTheTargetFileDoesNotExist(): void
    {
        $shell = new FakeShell();
        $target = new Target('output', [], [new Command('echo build')], false);

        self::assertTrue($target->run($shell, new FakeFilesystem(files: []), new FakeOutput()));
        self::assertSame(['echo build'], $shell->commands);
    }
}
