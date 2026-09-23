<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Console\CommandLine;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

final class CommandLineTest extends TestCase
{
    /** @throws MakefileErrorException */
    #[Test]
    public function acceptsAttachedAndLongOptionsAndAnOptionTerminator(): void
    {
        $arguments = new CommandLine([
            '-ffirst.mk',
            '--makefile=second.mk',
            '--file',
            'third.mk',
            '--quiet',
            '--',
            '-target',
        ]);

        self::assertSame(['first.mk', 'second.mk', 'third.mk'], $arguments->makefiles);
        self::assertSame(['-target'], $arguments->targets);
        self::assertTrue($arguments->silent);
        self::assertTrue(new CommandLine(['--version'])->version);
        self::assertTrue(new CommandLine(['-v'])->version);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function explicitArgumentsOverrideInheritedAssignments(): void
    {
        $arguments = new CommandLine(['CC=child'], ' -- CC=parent FLAGS=-DTEST');

        self::assertSame('child', $arguments->variables['CC']->expression ?? null);
        self::assertSame('-DTEST', $arguments->variables['FLAGS']->expression ?? null);
        self::assertSame([], $arguments->targets);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function passesSilentModeButNotMakefileSelectionToChildren(): void
    {
        $arguments = new CommandLine(['-sf', 'alternate.mk', 'VALUE=two words', 'all']);
        $child = new CommandLine([], $arguments->makeflags());

        self::assertSame(['alternate.mk'], $arguments->makefiles);
        self::assertSame(['all'], $arguments->targets);
        self::assertTrue($child->silent);
        self::assertSame([], $child->makefiles);
        self::assertSame('two words', $child->variables['VALUE']->expression ?? null);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function preservesSpacesBackslashesAndDollarsAcrossRecursiveInvocations(): void
    {
        $arguments = new CommandLine(['VALUE=two words\\path $$literal $(OTHER)', 'EMPTY=']);
        $child = new CommandLine([], $arguments->makeflags());
        $grandchild = new CommandLine([], $child->makeflags());

        self::assertEquals($arguments->variables, $child->variables);
        self::assertEquals($arguments->variables, $grandchild->variables);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function rejectsAMissingMakefileArgument(): void
    {
        $this->expectException(MakefileErrorException::class);
        new CommandLine(['-sf']);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function rejectsUnsupportedOptions(): void
    {
        $this->expectException(MakefileErrorException::class);
        new CommandLine(['--jobs=2']);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function separatesGoalsFromAssignmentsAndKeepsTheLastValue(): void
    {
        $arguments = new CommandLine(['CC=first', 'all', 'CC=second -std=c99', 'test', 'EMPTY=']);

        self::assertSame(['all', 'test'], $arguments->targets);
        self::assertSame('second -std=c99', $arguments->variables['CC']->expression ?? null);
        self::assertSame('', $arguments->variables['EMPTY']->expression ?? null);
    }
}
