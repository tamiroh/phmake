<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Console\CommandLine;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;

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
    public function conditionalAssignmentDoesNotPromoteAnEnvironmentDefinition(): void
    {
        $arguments = new CommandLine(['X?=cli'], defaults: ['X' => new Variable('X', 'env', origin: 'environment')]);
        self::assertSame([], $arguments->variables);
        self::assertSame('', $arguments->makeflags());
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
    public function ignoresNonInheritedFileAndDirectorySelections(): void
    {
        $arguments = new CommandLine([], '-f absent.mk -C absent -- VALUE=kept');
        self::assertSame([], $arguments->makefiles);
        self::assertSame([], $arguments->directories);
        self::assertSame('kept', $arguments->variables['VALUE']->expression);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function normalizesFlagsFromAllSourcesWithoutPropagatingDirectoriesOrGoals(): void
    {
        $arguments = new CommandLine(['-C', 'first', '--directory=second', '-R', '--no-silent', 'goal'], 's', '-e -r');
        $child = new CommandLine([], $arguments->makeflags());

        self::assertSame(['first', 'second'], $arguments->directories);
        self::assertSame('erR', $arguments->makeflags());
        self::assertTrue($child->noBuiltinRules);
        self::assertTrue($child->noBuiltinVariables);
        self::assertTrue($child->environmentOverrides);
        self::assertFalse($child->silent);
        self::assertSame([], $child->directories);
        self::assertSame([], $child->targets);
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
    public function rejectsAnEmptyLongOptionArgumentInsteadOfConsumingTheNextGoal(): void
    {
        $this->expectException(MakefileErrorException::class);
        new CommandLine(['--file=', 'all']);
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
    public function retainsSimpleAndRecursiveAssignmentsAcrossGenerations(): void
    {
        $arguments = new CommandLine(['X=before', 'S:=$(X) $$literal', 'S+= tail', 'R=$(X)', 'X=after', 'X?=ignored']);
        $child = new CommandLine([], $arguments->makeflags());
        $grandchild = new CommandLine([], $child->makeflags());

        self::assertSame('before $literal tail', $arguments->variables['S']->expression);
        self::assertFalse($arguments->variables['S']->recursive);
        self::assertSame('$(X)', $arguments->variables['R']->expression);
        self::assertSame('after', $arguments->variables['X']->expression);
        self::assertEquals($arguments->variables, $child->variables);
        self::assertEquals($arguments->variables, $grandchild->variables);
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
