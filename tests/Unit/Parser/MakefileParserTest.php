<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Command;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;

class MakefileParserTest extends TestCase
{
    /** @throws MakefileErrorException */
    #[Test]
    public function parsedAsExpected(): void
    {
        $makefile = new MakefileParser(<<<MAKEFILE
            GREETING = hello
            .PHONY: foo baz
            foo: bar baz
            \techo "$(GREETING) foo"
            bar: qux
            \techo "bar"
            baz:
            \techo "baz"
            qux:
            \techo "qux"

            MAKEFILE)->parse();

        $fooTarget = $makefile->targets[0];
        self::assertSame('foo', $fooTarget->name);
        self::assertTrue($fooTarget->isPhony);
        self::assertSame('bar', $fooTarget->dependencies[0]);
        self::assertSame('baz', $fooTarget->dependencies[1]);
        self::assertEquals([new Command('echo "$(GREETING) foo"')], $fooTarget->commands);

        $barTarget = $makefile->targets[1];
        self::assertSame('bar', $barTarget->name);
        self::assertFalse($barTarget->isPhony);
        self::assertSame('qux', $barTarget->dependencies[0]);
        self::assertEquals([new Command('echo "bar"')], $barTarget->commands);

        $bazTarget = $makefile->targets[2];
        self::assertSame('baz', $bazTarget->name);
        self::assertTrue($bazTarget->isPhony);
        self::assertEmpty($bazTarget->dependencies);
        self::assertEquals([new Command('echo "baz"')], $bazTarget->commands);

        $quxTarget = $makefile->targets[3];
        self::assertSame('qux', $quxTarget->name);
        self::assertFalse($quxTarget->isPhony);
        self::assertEmpty($quxTarget->dependencies);
        self::assertEquals([new Command('echo "qux"')], $quxTarget->commands);

        self::assertEquals([new Variable('GREETING', 'hello')], $makefile->variables);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function mergesRulesWithoutResolvingDependencies(): void
    {
        $makefile = new MakefileParser(
            "all: later input.txt\nall: extra\nlater: all\n.PHONY: all clean 123\n",
        )->parse();

        self::assertSame('all', $makefile->defaultGoal);
        self::assertSame(['later', 'input.txt', 'extra'], $makefile->targets[0]->dependencies);
        self::assertSame(['all'], $makefile->targets[1]->dependencies);
        self::assertTrue($makefile->targets[0]->isPhony);
        self::assertSame('clean', $makefile->targets[2]->name);
        self::assertTrue($makefile->targets[2]->isPhony);
        self::assertSame('123', $makefile->targets[3]->name);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function preservesRecipesAndIgnoresMakeCommentsBetweenThem(): void
    {
        $makefile = new MakefileParser(
            "all: # prerequisites\n\techo '# shell'  \n# make comment\n\n\t  echo next\n",
        )->parse();

        self::assertSame([], $makefile->targets[0]->dependencies);
        self::assertEquals(
            [
                new Command("echo '# shell'  "),
                new Command('  echo next'),
            ],
            $makefile->targets[0]->commands,
        );
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function expandsHeadersImmediatelyAndRecipesLater(): void
    {
        $makefile = new MakefileParser(<<<MAKEFILE
            INPUT = old.txt
            FIXED := $(INPUT)
            DEFERRED = $(INPUT)
            all: $(INPUT)
            \techo $(INPUT) $(FIXED) $(DEFERRED)
            inline: ; echo $(INPUT) '# shell'
            INPUT = new.txt
            MAKEFILE)->parse();

        self::assertSame(['old.txt'], $makefile->targets[0]->dependencies);
        self::assertSame(
            'echo new.txt old.txt new.txt',
            new VariableExpander($makefile->variables)->expand($makefile->targets[0]->commands[0]->expression),
        );
        self::assertSame(
            "echo new.txt '# shell'",
            new VariableExpander($makefile->variables)->expand($makefile->targets[1]->commands[0]->expression),
        );
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function joinsNormalLinesButPreservesShellContinuations(): void
    {
        $makefile = new MakefileParser(
            "all: first \\\n second\n\techo one \\\n\t two\ninline: ; echo three \\\n\t four\n",
        )->parse();

        self::assertSame(['first', 'second'], $makefile->targets[0]->dependencies);
        self::assertSame("echo one \\\n two", $makefile->targets[0]->commands[0]->expression);
        self::assertSame("echo three \\\n four", $makefile->targets[1]->commands[0]->expression);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function switchesToRecipeContinuationAfterAContinuedHeader(): void
    {
        $makefile = new MakefileParser("all: first \\\n second ; echo one \\\n\t two\n")->parse();

        self::assertSame(['first', 'second'], $makefile->targets[0]->dependencies);
        self::assertSame("echo one \\\n two", $makefile->targets[0]->commands[0]->expression);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function supportsPathsMultipleTargetsAndCrLf(): void
    {
        $makefile = new MakefileParser("build/a build/b : input\tother\r\n\techo build\r\n")->parse();

        self::assertSame('build/a', $makefile->defaultGoal);
        self::assertSame(['input', 'other'], $makefile->targets[0]->dependencies);
        self::assertSame('build/b', $makefile->targets[1]->name);
        self::assertEquals([new Command('echo build')], $makefile->targets[1]->commands);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function keepsRecipeWhenLaterDeclarationOnlyAddsDependencies(): void
    {
        $makefile = new MakefileParser("all:\n\techo all\nall: input\n")->parse();

        self::assertSame(['input'], $makefile->targets[0]->dependencies);
        self::assertEquals([new Command('echo all')], $makefile->targets[0]->commands);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function handlesEscapedHashesAndCommentsInAssignments(): void
    {
        $makefile = new MakefileParser("VALUE = value\\#literal# comment\nall: input\\#name # comment\n")->parse();

        self::assertSame('value#literal', $makefile->variables[0]->expression);
        self::assertSame(['input#name'], $makefile->targets[0]->dependencies);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function allowsEmptyInputAndDoesNotChoosePhonyDeclarationsAsDefaultGoals(): void
    {
        self::assertNull(new MakefileParser('')->parse()->defaultGoal);
        self::assertNull(new MakefileParser('.PHONY: clean')->parse()->defaultGoal);
        self::assertSame('all', new MakefileParser(".hidden:\nall:\n")->parse()->defaultGoal);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function parsingTheSameSourceAgainDoesNotReuseMutableState(): void
    {
        $parser = new MakefileParser("all: input\n\techo all\n");

        self::assertEquals($parser->parse(), $parser->parse());
    }

    /** @throws MakefileErrorException */
    #[Test]
    #[DataProvider('invalidSources')]
    public function reportsTheSourceLineForInvalidSyntax(string $source, int $line): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage("Makefile:$line:");

        new MakefileParser($source)->parse();
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidSources(): iterable
    {
        yield 'orphan recipe' => ["\techo orphan", 1];
        yield 'space indented recipe' => ["all:\n    echo all", 2];
        yield 'unknown directive' => ['include other.mk', 1];
        yield 'after continuation' => ["all: one \\\n two\ninvalid", 3];
        yield 'double colon' => ['all::', 1];
        yield 'pattern rule' => ['%.o: %.c', 1];
        yield 'order only prerequisites' => ['all: | directory', 1];
        yield 'duplicate recipes' => ["all: ; echo first\nall: ; echo second", 2];
    }
}
