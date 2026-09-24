<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Command;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;

final class ConditionalsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidSources(): iterable
    {
        yield 'extra else' => ['else'];
        yield 'extra endif' => ['endif'];
        yield 'missing endif' => ["ifdef MISSING\nall:"];
        yield 'duplicate else' => ["ifdef MISSING\nelse\nelse\nendif"];
        yield 'else if after final else' => ["ifdef MISSING\nelse\nelse ifdef MISSING\nendif"];
        yield 'missing variable' => ["ifdef\nendif"];
        yield 'multiple variable names' => ["ifdef A B\nendif"];
        yield 'missing comma' => ["ifeq (one)\nendif"];
        yield 'unclosed parentheses' => ["ifeq (one,one\nendif"];
        yield 'unclosed quote' => ["ifeq 'one' 'one\nendif"];
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function permitsVariablesNamedAfterDirectives(): void
    {
        $makefile = new MakefileParser("ifdef = value\nelse := value\nendif ?= value\nall:")->parse();
        self::assertSame('value', $makefile->variables[0]->expression);
        self::assertCount(3, $makefile->variables);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function preservesWhitespaceInsideComparisonArguments(): void
    {
        $makefile = new MakefileParser(<<<'MAKEFILE'
            all:
            ifeq ( a,a)
            	echo wrong
            else
            	echo first
            endif
            ifeq (a , a)
            	echo second
            endif
            ifneq (a,a )
            	echo third
            endif
            MAKEFILE)->parse();
        self::assertEquals(
            [new Command('echo first'), new Command('echo second'), new Command('echo third')],
            $makefile->targets[0]->commands,
        );
    }

    /** @throws MakefileErrorException */
    #[Test]
    #[DataProvider('invalidSources')]
    public function rejectsMalformedConditionals(string $source): void
    {
        $this->expectException(ParseException::class);
        new MakefileParser($source)->parse();
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function switchesRecipePrefixAndPreservesContinuations(): void
    {
        $makefile = new MakefileParser(
            ".RECIPEPREFIX = >ignored\nfirst:\n>echo one \\\n> two\n.RECIPEPREFIX =\nsecond:\n\techo three\n",
        )->parse();
        self::assertEquals([new Command("echo one \\\n two")], $makefile->targets[0]->commands);
        self::assertEquals([new Command('echo three')], $makefile->targets[1]->commands);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function usesTheUnexpandedRecipePrefix(): void
    {
        $makefile = new MakefileParser(<<<'MAKEFILE'
            PREFIX = >
            .RECIPEPREFIX = $(PREFIX)
            all:
            $echo literal
            MAKEFILE)->parse();
        self::assertEquals([new Command('echo literal')], $makefile->targets[0]->commands);
    }
}
