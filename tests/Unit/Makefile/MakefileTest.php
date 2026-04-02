<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\MakefileUpToDateException;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\FakeShell;

final class MakefileTest extends TestCase
{
    #[Test]
    public function runsTheDefaultTargetWhenNoArgumentsAreGiven(): void
    {
        $makefile = new Makefile([
            new Target(name: 'foo', dependencies: [], startLineIndex: 0, endLineIndex: 1, commands: ['echo foo']),
            new Target(name: 'bar', dependencies: [], startLineIndex: 2, endLineIndex: 3, commands: ['echo bar']),
        ]);

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(exists: [], lastModified: []);

        $makefile->run([], $shell, $filesystem, $output);

        $this->assertSame(['echo foo'], $shell->commands);
        $this->assertSame(['echo foo'], $output->lines);
    }

    #[Test]
    public function throwsWhenTargetDoesNotExist(): void
    {
        $makefile = new Makefile([
            new Target(name: 'foo', dependencies: [], startLineIndex: 0, endLineIndex: 1, commands: ['echo foo']),
        ]);

        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessage("No rule to make target `bar'");

        $makefile->run(['bar'], new FakeShell(), new FakeFilesystem(exists: [], lastModified: []), new FakeOutput());
    }

    #[Test]
    public function throwsWhenRequestedTargetIsUpToDate(): void
    {
        $makefile = new Makefile([
            new Target(name: 'foo', dependencies: [], startLineIndex: 0, endLineIndex: 1, commands: ['echo foo']),
        ]);

        try {
            $makefile->run(
                ['foo'],
                new FakeShell(),
                new FakeFilesystem(exists: ['foo' => true], lastModified: ['foo' => 100]),
                new FakeOutput(),
            );
            self::fail('Expected MakefileUpToDateException to be thrown.');
        } catch (MakefileUpToDateException $e) {
            $this->assertSame('foo', $e->target);
        }
    }
}
