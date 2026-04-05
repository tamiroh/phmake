<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\MakefileUpToDateException;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\FakeShell;

final class MakefileTest extends TestCase
{
    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     * @throws MakefileUpToDateException
     */
    #[Test]
    public function runsTheDefaultTargetWhenNoArgumentsAreGiven(): void
    {
        $makefile = new Makefile([
            new Target(
                name: 'foo',
                dependencies: [],
                startLineIndex: 0,
                endLineIndex: 1,
                commands: ['echo foo'],
                isPhony: false,
            ),
            new Target(
                name: 'bar',
                dependencies: [],
                startLineIndex: 2,
                endLineIndex: 3,
                commands: ['echo bar'],
                isPhony: false,
            ),
        ]);

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: []);

        $makefile->run([], $shell, $filesystem, $output);

        $this->assertSame(['echo foo'], $shell->commands);
        $this->assertSame(['echo foo'], $output->lines);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     * @throws MakefileUpToDateException
     */
    #[Test]
    public function throwsWhenTargetDoesNotExist(): void
    {
        $makefile = new Makefile([
            new Target(
                name: 'foo',
                dependencies: [],
                startLineIndex: 0,
                endLineIndex: 1,
                commands: ['echo foo'],
                isPhony: false,
            ),
        ]);

        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessage("No rule to make target `bar'");

        $makefile->run(['bar'], new FakeShell(), new FakeFilesystem(files: []), new FakeOutput());
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function throwsWhenRequestedTargetIsUpToDate(): void
    {
        $makefile = new Makefile([
            new Target(
                name: 'foo',
                dependencies: [],
                startLineIndex: 0,
                endLineIndex: 1,
                commands: ['echo foo'],
                isPhony: false,
            ),
        ]);

        try {
            $makefile->run(
                ['foo'],
                new FakeShell(),
                new FakeFilesystem(files: [
                    'foo' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
                ]),
                new FakeOutput(),
            );
            self::fail('Expected MakefileUpToDateException to be thrown.');
        } catch (MakefileUpToDateException $e) {
            $this->assertSame('foo', $e->target);
        }
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     * @throws MakefileUpToDateException
     */
    #[Test]
    public function stopsRunningLaterTargetsWhenAnEarlierTargetFails(): void
    {
        $makefile = new Makefile([
            new Target(
                name: 'foo',
                dependencies: [],
                startLineIndex: 0,
                endLineIndex: 1,
                commands: ['false'],
                isPhony: false,
            ),
            new Target(
                name: 'bar',
                dependencies: [],
                startLineIndex: 2,
                endLineIndex: 3,
                commands: ['echo bar'],
                isPhony: false,
            ),
        ]);

        $shell = new FakeShell();
        $shell->exitCodes['false'] = 1;

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessage('[foo] Error 1');

        try {
            $makefile->run(['foo', 'bar'], $shell, new FakeFilesystem(files: []), new FakeOutput());
        } finally {
            $this->assertSame(['false'], $shell->commands);
        }
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     * @throws MakefileUpToDateException
     */
    #[Test]
    public function runsPhonyTargetsEvenWhenTheCorrespondingFileExists(): void
    {
        $makefile = new Makefile([
            new Target(
                name: 'foo',
                dependencies: [],
                startLineIndex: 0,
                endLineIndex: 1,
                commands: ['echo foo'],
                isPhony: true,
            ),
        ]);

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: [
            'foo' => ['modifiedAt' => new \DateTimeImmutable('2026-04-05 10:00:00')],
        ]);

        $makefile->run(['foo'], $shell, $filesystem, $output);

        $this->assertSame(['echo foo'], $shell->commands);
        $this->assertSame(['echo foo'], $output->lines);
    }
}
