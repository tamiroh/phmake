<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Console\CommandLine;

final class CommandLineTest extends TestCase
{
    #[Test]
    public function explicitArgumentsOverrideInheritedAssignments(): void
    {
        $arguments = new CommandLine(['CC=child'], ' -- CC=parent FLAGS=-DTEST');

        self::assertSame('child', $arguments->variables['CC']->expression ?? null);
        self::assertSame('-DTEST', $arguments->variables['FLAGS']->expression ?? null);
        self::assertSame([], $arguments->targets);
    }

    #[Test]
    public function preservesSpacesBackslashesAndDollarsAcrossRecursiveInvocations(): void
    {
        $arguments = new CommandLine(['VALUE=two words\\path $$literal $(OTHER)', 'EMPTY=']);
        $child = new CommandLine([], $arguments->makeflags());
        $grandchild = new CommandLine([], $child->makeflags());

        self::assertEquals($arguments->variables, $child->variables);
        self::assertEquals($arguments->variables, $grandchild->variables);
    }

    #[Test]
    public function separatesGoalsFromAssignmentsAndKeepsTheLastValue(): void
    {
        $arguments = new CommandLine(['CC=first', 'all', 'CC=second -std=c99', 'test', 'EMPTY=']);

        self::assertSame(['all', 'test'], $arguments->targets);
        self::assertSame('second -std=c99', $arguments->variables['CC']->expression ?? null);
        self::assertSame('', $arguments->variables['EMPTY']->expression ?? null);
    }
}
