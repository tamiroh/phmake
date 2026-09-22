<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Command;
use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\FakeShell;

final class MakefileTest extends TestCase
{
    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function buildsSharedDependenciesOnceAcrossGoals(): void
    {
        $shell = new FakeShell();
        $makefile = new Makefile([
            new Target('left', ['shared'], [new Command('echo left')], true),
            new Target('right', ['shared'], [new Command('echo right')], true),
            new Target('shared', [], [new Command('echo shared')], true),
        ]);

        $makefile->run(['left', 'right'], $shell, new FakeFilesystem(files: []), new FakeOutput());

        self::assertSame(['echo shared', 'echo left', 'echo right'], $shell->commands);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function discardsExecutionStateAfterFailure(): void
    {
        $makefile = new Makefile([
            new Target('all', ['first', 'second'], [new Command('echo all')], true),
            new Target('first', [], [new Command('echo first')], true),
            new Target('second', [], [new Command('echo second')], true),
        ], defaultGoal: 'all');
        $shell = new FakeShell();
        $shell->exitCodes['echo second'] = 1;

        try {
            $makefile->run([], $shell, new FakeFilesystem(files: []), new FakeOutput());
            self::fail('Expected CommandFailedException to be thrown.');
        } catch (CommandFailedException $e) {
            self::assertSame('second', $e->target);
        }

        $shell->exitCodes['echo second'] = 0;
        $makefile->run([], $shell, new FakeFilesystem(files: []), new FakeOutput());

        self::assertSame(['echo first', 'echo second', 'echo first', 'echo second', 'echo all'], $shell->commands);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function dropsCircularDependenciesAndReportsWhenThereIsNoRecipe(): void
    {
        $makefile = new Makefile([
            new Target('first', ['second'], [], false),
            new Target('second', ['first'], [], false),
        ]);

        $output = new FakeOutput();
        $makefile->run(['first'], new FakeShell(), new FakeFilesystem(files: []), $output);

        self::assertSame(['Circular second <- first dependency dropped.'], $output->warnings);
        self::assertSame(["Nothing to be done for `first'."], $output->infos);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function ignoresDroppedDependenciesWhenComparingTimestamps(): void
    {
        $makefile = new Makefile([
            new Target('first', ['second'], [new Command('echo first')], false),
            new Target('second', ['first'], [new Command('echo second')], false),
        ]);
        $shell = new FakeShell();
        $output = new FakeOutput();

        $makefile->run(
            ['first'],
            $shell,
            new FakeFilesystem(files: [
                'first' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:01:00')],
                'second' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
            ]),
            $output,
        );

        self::assertSame([], $shell->commands);
        self::assertSame(['Circular second <- first dependency dropped.'], $output->warnings);
        self::assertSame(["`first' is up to date."], $output->infos);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function reportsMissingDefaultGoalAtExecutionTime(): void
    {
        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageIsOrContains('No targets');

        new Makefile()->run([], new FakeShell(), new FakeFilesystem(files: []), new FakeOutput());
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function reportsWhenRequestedTargetIsUpToDate(): void
    {
        $makefile = new Makefile([
            new Target(name: 'foo', dependencies: [], commands: [new Command('echo foo')], isPhony: false),
        ], defaultGoal: 'foo');

        $output = new FakeOutput();
        $makefile->run(
            ['foo'],
            new FakeShell(),
            new FakeFilesystem(files: [
                'foo' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
            ]),
            $output,
        );

        self::assertSame(["`foo' is up to date."], $output->infos);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function runsDependencyBeforeOwnCommands(): void
    {
        $bar = new Target(name: 'bar', dependencies: [], commands: [new Command('echo bar')], isPhony: false);
        $foo = new Target(name: 'foo', dependencies: ['bar'], commands: [new Command('echo foo')], isPhony: false);

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: []);

        new Makefile([$foo, $bar])->run(['foo'], $shell, $filesystem, $output);
        self::assertSame(['echo bar', 'echo foo'], $shell->commands);
        self::assertSame(['echo bar', 'echo foo'], $output->lines);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function runsPhonyTargetsEvenWhenTheCorrespondingFileExists(): void
    {
        $makefile = new Makefile([
            new Target(name: 'foo', dependencies: [], commands: [new Command('echo foo')], isPhony: true),
        ], defaultGoal: 'foo');

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: [
            'foo' => ['modifiedAt' => new \DateTimeImmutable('2026-04-05 10:00:00')],
        ]);

        $makefile->run(['foo'], $shell, $filesystem, $output);

        self::assertSame(['echo foo'], $shell->commands);
        self::assertSame(['echo foo'], $output->lines);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function runsTheDefaultTargetWhenNoArgumentsAreGiven(): void
    {
        $makefile = new Makefile([
            new Target(name: 'foo', dependencies: [], commands: [new Command('echo foo')], isPhony: false),
            new Target(name: 'bar', dependencies: [], commands: [new Command('echo bar')], isPhony: false),
        ], defaultGoal: 'foo');

        $shell = new FakeShell();
        $output = new FakeOutput();
        $filesystem = new FakeFilesystem(files: []);

        $makefile->run([], $shell, $filesystem, $output);

        self::assertSame(['echo foo'], $shell->commands);
        self::assertSame(['echo foo'], $output->lines);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function startsAFreshExecutionForEachRun(): void
    {
        $makefile = new Makefile([new Target('all', [], [new Command('echo all')], true)], defaultGoal: 'all');
        $shell = new FakeShell();

        $makefile->run([], $shell, new FakeFilesystem(files: []), new FakeOutput());
        $makefile->run([], $shell, new FakeFilesystem(files: []), new FakeOutput());

        self::assertSame(['echo all', 'echo all'], $shell->commands);
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function stopsBeforeLaterDependenciesAndParentWhenADependencyFails(): void
    {
        $shell = new FakeShell();
        $shell->exitCodes['false'] = 1;
        $makefile = new Makefile([
            new Target('all', ['first', 'second'], [new Command('echo all')], true),
            new Target('first', [], [new Command('false')], true),
            new Target('second', [], [new Command('echo second')], true),
        ]);

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessageIsOrContains('[first] Error 1');

        try {
            $makefile->run(['all'], $shell, new FakeFilesystem(files: []), new FakeOutput());
        } finally {
            self::assertSame(['false'], $shell->commands);
        }
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function stopsRunningLaterTargetsWhenAnEarlierTargetFails(): void
    {
        $makefile = new Makefile([
            new Target(name: 'foo', dependencies: [], commands: [new Command('false')], isPhony: false),
            new Target(name: 'bar', dependencies: [], commands: [new Command('echo bar')], isPhony: false),
        ], defaultGoal: 'foo');

        $shell = new FakeShell();
        $shell->exitCodes['false'] = 1;

        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessageIsOrContains('[foo] Error 1');

        try {
            $makefile->run(['foo', 'bar'], $shell, new FakeFilesystem(files: []), new FakeOutput());
        } finally {
            self::assertSame(['false'], $shell->commands);
        }
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function throwsWhenDependencyFileIsMissingAndHasNoRule(): void
    {
        $output = new Target('output', ['input.txt'], [new Command('cp input.txt output')], false);
        $shell = new FakeShell();

        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageIsOrContains("No rule to make target `input.txt', needed by `output'");

        try {
            new Makefile([$output])->run(['output'], $shell, new FakeFilesystem(files: []), new FakeOutput());
        } finally {
            self::assertSame([], $shell->commands);
        }
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function throwsWhenTargetDoesNotExist(): void
    {
        $makefile = new Makefile([
            new Target(name: 'foo', dependencies: [], commands: [new Command('echo foo')], isPhony: false),
        ], defaultGoal: 'foo');

        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageIsOrContains("No rule to make target `bar'");

        $makefile->run(['bar'], new FakeShell(), new FakeFilesystem(files: []), new FakeOutput());
    }

    /**
     * @throws CommandFailedException
     * @throws MakefileErrorException
     */
    #[Test]
    public function usesDependencyFileWithoutRuleForTimestampComparison(): void
    {
        $output = new Target('output', ['input.txt'], [new Command('cp input.txt output')], false);
        $shell = new FakeShell();

        $makefile = new Makefile([$output]);
        $messages = new FakeOutput();
        $makefile->run(
            ['output'],
            $shell,
            new FakeFilesystem(files: [
                'input.txt' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:00:00')],
                'output' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:01:00')],
            ]),
            $messages,
        );
        self::assertSame(["`output' is up to date."], $messages->infos);
        self::assertSame([], $shell->commands);
        $makefile->run(
            ['output'],
            $shell,
            new FakeFilesystem(files: [
                'input.txt' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:02:00')],
                'output' => ['modifiedAt' => new DateTimeImmutable('2026-04-05 10:01:00')],
            ]),
            new FakeOutput(),
        );

        self::assertSame(['cp input.txt output'], $shell->commands);
    }
}
