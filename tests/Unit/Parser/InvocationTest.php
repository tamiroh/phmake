<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Parser;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Console\CommandLine;
use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\FakeShell;

final class InvocationTest extends TestCase
{
    /** @throws MakefileErrorException */
    #[Test]
    public function appendingFlagsKeepsCommandLineAssignmentsSeparate(): void
    {
        $configuration = new CommandLine(['X=cli']);
        $makefile = new MakefileParser(<<<'MAKEFILE'
            MAKEFLAGS += -R
            MAKEFLAGS += -- Y=from-flags
            Y = ignored
            all:
            MAKEFILE, defaults: [
            ...Builtins::variables(),
            new Variable('MAKEFLAGS', $configuration->makeflags(), false),
        ], overrides: $configuration->variables, configuration: $configuration)->parse();
        self::assertTrue($configuration->noBuiltinVariables);
        self::assertSame(
            'undefined|cli|from-flags|command line',
            new VariableExpander($makefile->variables)->expand('$(origin CC)|$(X)|$(Y)|$(origin Y)'),
        );
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function exportAndOverrideModifiersCanAppearInEitherOrder(): void
    {
        $makefile = new MakefileParser(
            <<<'MAKEFILE'
                export override X = first
                override export Y = second
                override = ordinary variable
                all:
                MAKEFILE,
            overrides: ['X' => new Variable('X', 'cli', origin: 'command line')],
        )->parse();
        self::assertSame(
            'first|override|second|ordinary variable',
            new VariableExpander($makefile->variables)->expand('$(X)|$(origin X)|$(Y)|$(override)'),
        );
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    #[Test]
    public function makeflagsDisablesImplicitBuiltinRules(): void
    {
        $makefile = new MakefileParser(
            'MAKEFLAGS += -r',
            defaults: Builtins::variables(),
            builtinRules: Builtins::rules(),
            configuration: new CommandLine([]),
        )->parse();
        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageMatches("/No rule to make target `item[.]o'/");
        $makefile->run(
            ['item.o'],
            new FakeShell(),
            new FakeFilesystem([
                'item.c' => ['modifiedAt' => new DateTimeImmutable()],
            ]),
            new FakeOutput(),
        );
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function makeflagsUpdatesApplyBeforeTheNextDefinition(): void
    {
        $configuration = new CommandLine([]);
        $makefile = new MakefileParser(<<<'MAKEFILE'
            CC = custom
            MAKEFLAGS += -eR
            X = ignored
            override X += forced
            X := $(error ignored assignment must not expand)
            all:
            MAKEFILE, defaults: [
            ...Builtins::variables(),
            new Variable('X', 'environment', origin: 'environment'),
        ], configuration: $configuration)->parse();

        self::assertSame(
            'custom|undefined|environment forced|override|erR',
            new VariableExpander($makefile->variables)->expand(
                '$(CC)|$(origin COMPILE.c)|$(X)|$(origin X)|$(MAKEFLAGS)',
            ),
        );
    }
}
