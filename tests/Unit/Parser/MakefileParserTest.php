<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\Execution\Recipe\Command;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\ReadFile;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\Target;
use Tamiroh\Phmake\Parser\Configuration;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;
use Tamiroh\Phmake\Parser\Source\MakefileSources;
use Tamiroh\Phmake\Tests\Testing\FakeConfiguration;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\InMemorySourceFiles;

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
    private static function expand(Makefile $makefile, string $expression): string
    {
        return self::expander($makefile)->expand($expression);
    }

    private static function expander(Makefile $makefile): VariableExpander
    {
        return new VariableExpander($makefile->context ?? self::fail('The parser must keep its evaluation context.'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    private static function parse(MakefileSources|string $sources, ?FakeOutput $output = null): Makefile
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
            new InMemorySourceFiles($files, $directories),
            new FakeFilesystem($files),
            ['Makefile'],
            $configuration,
            evaluations: $evaluations,
        );
    }

    private static function target(Makefile $makefile, string $name): Target
    {
        return $makefile->targetsByName[$name] ?? self::fail("Target '{$name}' is missing.");
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function commentsEndAtAnUnescapedHash(): void
    {
        $makefile = self::parse("A = x \\# y # comment\nB = \$(subst x,#,axb)\n# C = hidden\n");
        self::assertSame('x # y ', self::expand($makefile, '$(A)'));
        self::assertSame('a#b', self::expand($makefile, '$(B)'));
        self::assertSame('', self::expand($makefile, '$(C)'));
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
        self::assertSame('first', self::parse(".PHONY: first\n%.o: %.c\nfirst: second\nsecond:\n")->defaultGoal);
        self::assertSame('second', self::parse("first:\nsecond:\n.DEFAULT_GOAL := second\n")->defaultGoal);
        self::assertNull(self::parse("A = 1\n")->defaultGoal);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function defineKeepsLinesAndNestedDefinitions(): void
    {
        $makefile = self::parse("define OUTER\nfirst\ndefine INNER\nsecond\nendef\n\tthird # kept\nendef\n"
        . "define SIMPLE :=\n\$(OUTER)\nendef\n");
        self::assertSame("first\ndefine INNER\nsecond\nendef\n\tthird # kept", self::expand($makefile, '$(OUTER)'));
        self::assertSame("first\ndefine INNER\nsecond\nendef\n\tthird # kept", self::expand(
            $makefile,
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
        $makefile = self::parse(self::sources(['Makefile' => "B := \$(A)\n"], evaluations: ['A = from-eval']));
        self::assertSame('from-eval', self::expand($makefile, '$(B)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function exportDirectivesSelectEnvironmentVariables(): void
    {
        $makefile = self::parse("export A = 1\nB = 2\nexport B\nC = 3\nexport D := 4\nunexport D\n");
        $environment = $makefile->exports->environment(self::expander($makefile), null);
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
        $makefile = self::parse(self::sources([
            'Makefile' => "A = before\ninclude part.mk\nB := \$(A)\n",
            'part.mk' => "A = included\n",
        ]));
        self::assertSame('included', self::expand($makefile, '$(B)'));
        self::assertSame('Makefile part.mk', self::expand($makefile, '$(MAKEFILE_LIST)'));
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
        $makefile = self::parse("a.o b.o: %.o: %.c %.h\n\tcc \$<\n");
        self::assertSame(['a.c', 'a.h'], self::rule(self::target($makefile, 'a.o'))->prerequisites->normal);
        self::assertSame(['b.c', 'b.h'], self::rule(self::target($makefile, 'b.o'))->prerequisites->normal);
        self::assertSame('b', self::rule(self::target($makefile, 'b.o'))->stem);
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function targetSpecificVariablesStayOutOfTheGlobalScope(): void
    {
        $makefile = self::parse("all: V = local\nall: override W += more\nall:\n");
        self::assertSame('local', $makefile->scopes->definitionsFor('all')['V']->expression ?? null);
        self::assertSame('override', $makefile->scopes->definitionsFor('all')['W']->origin ?? null);
        self::assertSame('', self::expand($makefile, '$(V)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function variableFlavorsControlWhenValuesExpand(): void
    {
        $makefile = self::parse(
            "A = \$(B)\nB = early\nC := \$(B)\nD ?= first\nD ?= second\nE = one\nE += two\n"
            . "B = late\nF ::= \$(B)\nG :::= \$(B) \$\$\$\$\nundefine E\n",
        );
        self::assertSame('late', self::expand($makefile, '$(A)'));
        self::assertSame('early', self::expand($makefile, '$(C)'));
        self::assertSame('first', self::expand($makefile, '$(D)'));
        self::assertSame('late', self::expand($makefile, '$(F)'));
        self::assertSame('late $$', self::expand($makefile, '$(G)'));
        self::assertSame('undefined', self::expand($makefile, '$(origin E)'));
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    #[Test]
    public function vpathDirectivesDefineSearchPaths(): void
    {
        $makefile = self::parse("vpath %.c src lib\n");
        $filesystem = new FakeFilesystem(['lib/a.c' => '']);
        self::assertSame('lib/a.c', $makefile->paths->find('a.c', $filesystem, self::expander($makefile), []));
        self::assertNull($makefile->paths->find('a.h', $filesystem, self::expander($makefile), []));
    }
}
