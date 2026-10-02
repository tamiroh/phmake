<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Expansion\VariableExpander;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\ReadFile;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\Command;
use Tamiroh\Phmake\Makefile\Rule\Target;
use Tamiroh\Phmake\Parser\Configuration;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParsedMakefile;
use Tamiroh\Phmake\Parser\ParseException;
use Tamiroh\Phmake\Parser\Source\MakefileSources;
use Tamiroh\Phmake\Tests\Testing\FakeConfiguration;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;

use function array_map;
use function array_slice;

final class MakefileParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conditionals(): iterable
    {
        yield 'ifeq takes the true branch' => ["ifeq (a,a)\nV = yes\nelse\nV = no\nendif\n", 'yes'];
        yield 'ifneq takes the else branch' => ["ifneq (a,a)\nV = yes\nelse\nV = no\nendif\n", 'no'];
        yield 'quoted ifeq arguments' => ["ifeq \"a\" 'a'\nV = yes\nendif\n", 'yes'];
        yield 'ifdef ignores empty variables' => ["E =\nifdef E\nV = yes\nelse\nV = no\nendif\n", 'no'];
        yield 'ifndef sees undefined variables' => ["ifndef U\nV = yes\nendif\n", 'yes'];
        yield 'else chains another conditional' => [
            "ifeq (a,b)\nV = first\nelse ifeq (a,a)\nV = second\nelse\nV = third\nendif\n",
            'second',
        ];
        yield 'inactive branches skip nested conditionals' => [
            "ifeq (a,b)\nifeq (a,a)\nV = inner\nendif\nelse\nV = outer\nendif\n",
            'outer',
        ];
        yield 'inactive branches skip recipes and rules' => ["ifdef U\nall:\n\techo\nendif\nV = kept\n", 'kept'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function errors(): iterable
    {
        yield 'missing endif' => ["ifeq (a,a)\nA = 1\n", "missing 'endif'", 'Makefile:3'];
        yield 'extraneous else' => ["A = 1\nelse\n", "extraneous 'else'", 'Makefile:2'];
        yield 'second else' => ["ifdef X\nelse\nelse\nendif\n", "only one 'else' per conditional", 'Makefile:3'];
        yield 'extraneous endif' => ["endif\n", "extraneous 'endif'", 'Makefile:1'];
        yield 'missing endef' => ["define B\nx\n", "missing 'endef', unterminated 'define'", 'Makefile:1'];
        yield 'missing separator' => ["A = 1\nfoo\n", 'missing separator', 'Makefile:2'];
        yield 'spaces instead of a tab' => [
            "        echo x\n",
            'missing separator (did you mean TAB instead of 8 spaces?)',
            'Makefile:1',
        ];
        yield 'ifeq without whitespace' => [
            "ifeq(a,a)\nendif\n",
            'missing separator (ifeq/ifneq must be followed by whitespace)',
            'Makefile:1',
        ];
        yield 'multiple target patterns' => ["a.o b.o: %.o %.x: %.c\n", 'multiple target patterns', 'Makefile:1'];
        yield 'target pattern without a percent' => ["a.o: x.o: %.c\n", "target pattern contains no '%'", 'Makefile:1'];
        yield 'grouped targets without a recipe' => ["a b&:\n", 'grouped targets must provide a recipe', 'Makefile:1'];
        yield 'mixed colons' => [
            "a:\n\techo\na::\n\techo\n",
            "target file 'a' has both : and :: entries",
            'Makefile:3',
        ];
        yield 'line numbers count continuations' => ["A = 1 \\\n  2\nfoo\n", 'missing separator', 'Makefile:3'];
    }

    /**
     * @throws ParseException
     */
    private static function assertRejected(MakefileSources|string $sources, string $message, string $source): void
    {
        try {
            self::parse($sources);
        } catch (MakefileErrorException $error) {
            self::assertSame($message, $error->getMessage());
            self::assertSame($source, $error->source);
            return;
        }
        self::fail('The makefile must be rejected.');
    }

    /**
     * @return list<string>
     */
    private static function commands(Target $target): array
    {
        return array_map(static fn(Command $command): string => $command->expression, self::recipe($target));
    }

    /**
     * @throws MakefileErrorException
     */
    private static function expand(ParsedMakefile $parsed, string $expression): string
    {
        return self::expander($parsed)->expand($expression);
    }

    private static function expander(ParsedMakefile $parsed): VariableExpander
    {
        return new VariableExpander($parsed->context);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    private static function parse(MakefileSources|string $sources, ?FakeOutput $output = null): ParsedMakefile
    {
        return new MakefileParser(
            $sources instanceof MakefileSources ? $sources : self::sources(['Makefile' => $sources]),
            output: $output ?? new FakeOutput(),
            filesystem: new FakeFilesystem(),
        )->parse();
    }

    private static function read(MakefileSources $sources, int $index): ReadFile
    {
        return $sources->read[$index] ?? self::fail("Read file {$index} is missing.");
    }

    /**
     * @return list<Command>
     */
    private static function recipe(Target $target): array
    {
        return self::rule($target)->recipe->commands ?? [];
    }

    private static function rule(Target $target): BuildRule
    {
        return $target->rules[0] ?? self::fail("Target '{$target->name}' has no rule.");
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $directories
     * @param list<string> $evaluations
     */
    private static function sources(
        array $files,
        ?Configuration $configuration = null,
        array $directories = [],
        array $evaluations = [],
    ): MakefileSources {
        return new MakefileSources(
            new FakeFilesystem($files, $directories),
            ['Makefile'],
            $configuration,
            evaluations: $evaluations,
        );
    }

    private static function target(ParsedMakefile $parsed, string $name): Target
    {
        return $parsed->makefile->targetsByName[$name] ?? self::fail("Target '{$name}' is missing.");
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function commentsEndAtAnUnescapedHash(): void
    {
        $parsed = self::parse("A = x \\# y # comment\nB = \$(subst x,#,axb)\n# C = hidden\n");
        self::assertSame('x # y ', self::expand($parsed, '$(A)'));
        self::assertSame('a#b', self::expand($parsed, '$(B)'));
        self::assertSame('', self::expand($parsed, '$(C)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function continuationLinesJoinWithASingleSpace(): void
    {
        self::assertSame('one two three', self::expand(self::parse("A = one \\\n    two\\\n three\n"), '$(A)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function defaultGoalIsTheFirstOrdinaryTarget(): void
    {
        self::assertSame(
            'first',
            self::parse(".PHONY: first\n%.o: %.c\nfirst: second\nsecond:\n")->makefile->defaultGoal,
        );
        self::assertSame('second', self::parse("first:\nsecond:\n.DEFAULT_GOAL := second\n")->makefile->defaultGoal);
        self::assertNull(self::parse("A = 1\n")->makefile->defaultGoal);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function defineKeepsLinesAndNestedDefinitions(): void
    {
        $parsed = self::parse("define OUTER\nfirst\ndefine INNER\nsecond\nendef\n\tthird # kept\nendef\n"
        . "define SIMPLE :=\n\$(OUTER)\nendef\n");
        self::assertSame("first\ndefine INNER\nsecond\nendef\n\tthird # kept", self::expand($parsed, '$(OUTER)'));
        self::assertSame("first\ndefine INNER\nsecond\nendef\n\tthird # kept", self::expand(
            $parsed,
            '$(value SIMPLE)',
        ));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    #[DataProvider('conditionals')]
    public function evaluatesConditionals(string $text, string $expected): void
    {
        self::assertSame($expected, self::expand(self::parse($text), '$(V)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function evaluationsAreReadBeforeMakefiles(): void
    {
        $parsed = self::parse(self::sources(['Makefile' => "B := \$(A)\n"], evaluations: ['A = from-eval']));
        self::assertSame('from-eval', self::expand($parsed, '$(B)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function exportDirectivesSelectEnvironmentVariables(): void
    {
        $parsed = self::parse("export A = 1\nB = 2\nexport B\nC = 3\nexport D := 4\nunexport D\n");
        $environment = $parsed->makefile->exports->environment(self::expander($parsed), null);
        self::assertSame('1', $environment['A'] ?? null);
        self::assertSame('2', $environment['B'] ?? null);
        self::assertArrayNotHasKey('C', $environment);
        self::assertFalse($environment['D'] ?? null);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function includeReadsFilesInPlace(): void
    {
        $parsed = self::parse(self::sources([
            'Makefile' => "A = before\ninclude part.mk\nB := \$(A)\n",
            'part.mk' => "A = included\n",
        ]));
        self::assertSame('included', self::expand($parsed, '$(B)'));
        self::assertSame('Makefile part.mk', self::expand($parsed, '$(MAKEFILE_LIST)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function includeRecordsMissingFilesForRemaking(): void
    {
        $output = new FakeOutput();
        $sources = self::sources(['Makefile' => "include missing.mk\n-include optional.mk\n"]);
        self::parse($sources, $output);
        self::assertSame(
            [
                ['missing.mk',  false, 'Makefile:1'],
                ['optional.mk', true,  'Makefile:2'],
            ],
            array_map(
                static fn(ReadFile $file): array => [$file->path, $file->optional, $file->source],
                array_slice($sources->read, offset: 1),
            ),
        );
        self::assertNull(self::read($sources, 1)->text);
        self::assertSame([], $output->warnings);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function includeSearchesIncludeDirectories(): void
    {
        $sources = self::sources(
            ['Makefile' => "include part.mk\n", 'dir/part.mk' => "A = found\n"],
            new FakeConfiguration(['dir']),
            ['dir'],
        );
        self::assertSame('found', self::expand(self::parse($sources), '$(A)'));
        self::assertSame('dir/part.mk', self::read($sources, 1)->path);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function readsRecipesAfterRulesAndSemicolons(): void
    {
        $target = self::target(self::parse("all: a b | c ; first\n\tsecond\n\n# comment\n\tthird\n"), 'all');
        self::assertSame(['a', 'b'], self::rule($target)->prerequisites->normal);
        self::assertSame(['c'], self::rule($target)->prerequisites->orderOnly);
        self::assertSame(['first', 'second', 'third'], self::commands($target));
        self::assertSame(
            ['Makefile:1', 'Makefile:2', 'Makefile:5'],
            array_map(static fn(Command $command): ?string => $command->source, self::recipe($target)),
        );
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function recipePrefixCanBeChanged(): void
    {
        self::assertSame(
            ['echo hi'],
            self::commands(self::target(self::parse(".RECIPEPREFIX = >\nall:\n>echo hi\n"), 'all')),
        );
    }

    /**
     * @throws ParseException
     */
    #[Test]
    public function rejectsRecursiveIncludes(): void
    {
        self::assertRejected(
            self::sources(['Makefile' => "include Makefile\n"]),
            "Recursive include `Makefile'",
            'Makefile:1',
        );
    }

    /**
     * @throws ParseException
     */
    #[Test]
    public function reportsErrorsInIncludedFilesAtTheirOwnLocation(): void
    {
        self::assertRejected(
            self::sources(['Makefile' => "A = 1\ninclude part.mk\n", 'part.mk' => "B = 2\nfoo\n"]),
            'missing separator',
            'part.mk:2',
        );
    }

    /**
     * @throws ParseException
     */
    #[Test]
    #[DataProvider('errors')]
    public function reportsErrorsWithTheirLocation(string $text, string $message, string $source): void
    {
        self::assertRejected($text, $message, $source);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function staticPatternRulesSubstituteEachTarget(): void
    {
        $parsed = self::parse("a.o b.o: %.o: %.c %.h\n\tcc \$<\n");
        self::assertSame(['a.c', 'a.h'], self::rule(self::target($parsed, 'a.o'))->prerequisites->normal);
        self::assertSame(['b.c', 'b.h'], self::rule(self::target($parsed, 'b.o'))->prerequisites->normal);
        self::assertSame('b', self::rule(self::target($parsed, 'b.o'))->stem);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function targetSpecificVariablesStayOutOfTheGlobalScope(): void
    {
        $parsed = self::parse("all: V = local\nall: override W += more\nall:\n");
        self::assertSame('local', $parsed->makefile->targetVariables->definitionsFor('all')['V']->expression ?? null);
        self::assertSame('override', $parsed->makefile->targetVariables->definitionsFor('all')['W']->origin ?? null);
        self::assertSame('', self::expand($parsed, '$(V)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function variableFlavorsControlWhenValuesExpand(): void
    {
        $parsed = self::parse(
            "A = \$(B)\nB = early\nC := \$(B)\nD ?= first\nD ?= second\nE = one\nE += two\n"
            . "B = late\nF ::= \$(B)\nG :::= \$(B) \$\$\$\$\nundefine E\n",
        );
        self::assertSame('late', self::expand($parsed, '$(A)'));
        self::assertSame('early', self::expand($parsed, '$(C)'));
        self::assertSame('first', self::expand($parsed, '$(D)'));
        self::assertSame('late', self::expand($parsed, '$(F)'));
        self::assertSame('late $$', self::expand($parsed, '$(G)'));
        self::assertSame('undefined', self::expand($parsed, '$(origin E)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function vpathDirectivesDefineSearchPaths(): void
    {
        $parsed = self::parse("vpath %.c src lib\n");
        $filesystem = new FakeFilesystem(['lib/a.c' => '']);
        self::assertSame('lib/a.c', $parsed->makefile->searchPaths->find(
            'a.c',
            $filesystem,
            self::expander($parsed),
            [],
        ));
        self::assertNull($parsed->makefile->searchPaths->find('a.h', $filesystem, self::expander($parsed), []));
    }
}
