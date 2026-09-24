<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Command;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\FakeShell;

final class CommandTest extends TestCase
{
    /** @throws MakefileErrorException */
    #[Test]
    public function displaysAndExecutesTheExpandedCommand(): void
    {
        $shell = new FakeShell();
        $output = new FakeOutput();

        $exitCode = new Command('echo $(GREETING)')->run($shell, $output, [new Variable('GREETING', 'hello')]);

        self::assertSame(0, $exitCode);
        self::assertSame(['echo hello'], $shell->commands);
        self::assertSame(['echo hello'], $output->lines);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function doesNotExecuteEmptyExpandedRecipes(): void
    {
        $shell = new FakeShell();
        self::assertSame(0, new Command('@$(UNDEFINED)')->run($shell, new FakeOutput()));
        self::assertSame([], $shell->commands);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function ignoresFailureOnlyForRecipesWithMinusPrefix(): void
    {
        $shell = new FakeShell();
        $shell->exitCodes['false'] = 7;
        $output = new FakeOutput();

        self::assertSame(0, new Command(' -@false')->run($shell, $output));
        self::assertSame(['false'], $shell->commands);
        self::assertSame([], $output->lines);
        self::assertSame(7, new Command('@false')->run($shell, $output));
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function literalPrefixesApplyToEveryLineOfAMultilineRecipe(): void
    {
        $shell = new FakeShell();
        $shell->exitCodes['false'] = 7;
        $output = new FakeOutput();
        self::assertSame(0, new Command('-@$(BODY)')->run($shell, $output, [new Variable(
            'BODY',
            "false\necho continued\nfalse",
        )]));
        self::assertSame(['false', 'echo continued', 'false'], $shell->commands);
        self::assertSame([], $output->lines);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function multilineRecipesStopAtTheFirstUnignoredFailure(): void
    {
        $shell = new FakeShell();
        $shell->exitCodes['false'] = 7;
        self::assertSame(7, new Command('$(BODY)')->run($shell, new FakeOutput(), [new Variable(
            'BODY',
            "-false\nfalse\necho unreachable",
        )]));
        self::assertSame(['false', 'false'], $shell->commands);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function returnsTheShellExitCode(): void
    {
        $shell = new FakeShell();
        $shell->exitCodes['false'] = 7;

        self::assertSame(7, new Command('false')->run($shell, new FakeOutput()));
    }
}
