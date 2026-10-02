<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile\Expansion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Expansion\VariableExpander;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable\Variable;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;

final class VariableExpanderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conditionalExpressions(): iterable
    {
        yield 'true branch skips recursion' => ['$(if yes,result,$(CYCLE))', 'result'];
        yield 'false branch skips recursion' => ['$(if ,$(CYCLE),result)', 'result'];
        yield 'false and skips recursion' => ['$(and ,$(CYCLE))', ''];
        yield 'true or skips recursion' => ['$(or result,$(CYCLE))', 'result'];
        yield 'and returns last value' => ['$(and first,second)', 'second'];
        yield 'or returns first nonempty value' => ['$(or ,,third,fourth)', 'third'];
        yield 'missing else is empty' => ['$(if ,yes)', ''];
        yield 'commas belong to final branch' => ['$(if ,yes,no,more)', 'no,more'];
        yield 'literal whitespace is stripped' => ["$(if \t ,yes,no)", 'no'];
        yield 'expanded whitespace is true' => ['$(if $(SPACE),yes,no)', 'yes'];
        yield 'expanded whitespace is preserved by or' => ['$(or $(SPACE),fallback)', ' '];
        yield 'empty and' => ['$(and )', ''];
        yield 'empty or' => ['$(or )', ''];
        yield 'dynamic variable name is not a function' => ['$($(NAME) ,yes,no)', ''];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foreachExpressions(): iterable
    {
        yield 'words' => ["$(foreach x, a\tb\nc ,[\$x])", '[a] [b] [c]'];
        yield 'empty list skips body' => ['$(foreach x, ,$(CYCLE))', ''];
        yield 'empty results keep separators' => ['$(foreach x,a b c,)', '  '];
        yield 'body whitespace and commas' => ['$(foreach x,a b, [$x], )', ' [a],   [b], '];
        yield 'expanded name uses first word' => ['$(foreach $(NAME),a b,$x)', 'a b'];
        yield 'empty name still expands body' => ['$(foreach ,a b,[$()])', '[] []'];
        yield 'list uses outer scope' => ['$(foreach x,$x,$x)', 'outer'];
        yield 'nested scope restores outer variable' => [
            '$(foreach x,a b,$x:$(foreach x,1 2,$x):$x)|$x',
            'a:1 2:a b:1 2:b|outer',
        ];
        yield 'words are simple variables' => ['$(foreach x,$$(literal),$x)', '$(literal)'];
        yield 'call expands arguments before foreach' => ['$(call foreach,x,a b,$x)', 'outer outer'];
        yield 'call can defer body expansion' => ['$(call foreach,x,a b,$$x)', 'a b'];
        yield 'builtin wins over variable' => ['$(call foreach,x,a b,yes)', 'yes yes'];
        yield 'nested call keeps parameter masking' => ['$(call relay,one,two)', 'inner:'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function functionExpressions(): iterable
    {
        yield 'last argument retains commas' => ['$(subst a,b,a,a)', 'b,b'];
        yield 'expanded commas do not split arguments' => ['$(subst $(COMMA),!,a,b)', 'a!b'];
        yield 'nested delimiters retain commas' => ['$(subst (a,b),x,(a,b))', 'x'];
        yield 'function name is recognized before expansion' => ['$($(FUNCTION) a,b,a)', ''];
        yield 'empty substitution appends' => ['$(subst ,tail,head)', 'headtail'];
        yield 'literal patterns match whole words' => ['$(patsubst a,b,aa a)', 'aa b'];
        yield 'only the first percent substitutes' => ['$(patsubst %.c,obj/%.%,a.c)', 'obj/a.%'];
        yield 'escaped replacement percent' => ['$(patsubst %.c,\%.o,a.c)', '%.o'];
        yield 'sort is lexical' => ['$(sort 2 10 2 1)', '1 10 2'];
        yield 'all whitespace separates words' => ["$(strip a\tb\nc\rd\fe)", 'a b c d e'];
        yield 'absent word' => ['$(word 9223372036854775807,a b)', ''];
        yield 'empty word list range' => ['$(wordlist 9223372036854775807,0,a b)', ''];
        yield 'leading zeros in indexes' => ['$(word 0002,a b)', 'b'];
        yield 'empty final word' => ['$(lastword )', ''];
        yield 'empty prefix input' => ['$(addprefix out/,)', ''];
        yield 'empty pattern replacement' => ['$(patsubst %,,$(LIST))', ''];
        yield 'escaped filter pattern' => ['$(filter a\%b,a%b axb)', 'a%b'];
        yield 'literal replacement preserves whitespace' => ['$(patsubst a,x, a   b )', ' x   b '];
        yield 'literal replacement keeps percent' => ['$(patsubst a,%,a a b)', '% % b'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFunctionExpressions(): iterable
    {
        yield 'missing argument' => ['$(subst a,b)'];
        yield 'missing value argument' => ['$(call value)'];
        yield 'missing flavor argument' => ['$(call flavor)'];
        yield 'missing origin argument' => ['$(call origin)'];
        yield 'empty index' => ['$(word ,a)'];
        yield 'zero index' => ['$(word 0,a)'];
        yield 'negative index' => ['$(word -1,a)'];
        yield 'non-numeric index' => ['$(word 1x,a)'];
        yield 'overflowing index' => ['$(word 9999999999999999999,a)'];
        yield 'zero range start' => ['$(wordlist 0,1,a)'];
        yield 'negative range end' => ['$(wordlist 1,-1,a)'];
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function errorStopsExpansionBeforeLaterSideEffects(): void
    {
        $output = new FakeOutput();
        try {
            new VariableExpander([], $output, source: 'example.mk:3')->expand('$(error stopped)$(info must-not-run)');
            self::fail('The error function must stop expansion.');
        } catch (MakefileErrorException $error) {
            self::assertSame('stopped', $error->getMessage());
            self::assertSame('example.mk:3', $error->source);
            self::assertSame([], $output->writes);
        }
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function expandsAllCallArgumentsBeforeInvokingLazyBuiltins(): void
    {
        $this->expectException(MakefileErrorException::class);
        new VariableExpander([new Variable('CYCLE', '$(CYCLE)')])->expand('$(call if,yes,result,$(CYCLE))');
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function expandsCallArgumentsOnceAndLetsBuiltinsTakePrecedence(): void
    {
        self::assertSame(
            '$(literal)',
            new VariableExpander([
                new Variable('subst', 'shadowed'),
            ])->expand('$(call subst,x,y,$$(literal),ignored)'),
        );
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function expandsForeachNameAndListOnceBeforeBody(): void
    {
        $output = new FakeOutput();
        self::assertSame(
            'a b',
            new VariableExpander([], $output)->expand('$(foreach $(info name)x,$(info list)a b,$(info $x)$x)'),
        );
        self::assertSame(["name\n", "list\n", "a\n", "b\n"], $output->writes);
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    #[DataProvider('foreachExpressions')]
    public function expandsForeachWithTemporaryScope(string $expression, string $expected): void
    {
        self::assertSame(
            $expected,
            new VariableExpander([
                new Variable('x', 'outer'),
                new Variable('NAME', ' x extra '),
                new Variable('CYCLE', '$(CYCLE)'),
                new Variable('foreach', 'shadowed'),
                new Variable('pair', '$1:$2'),
                new Variable('relay', '$(foreach x,item,$(call pair,inner))'),
            ])->expand($expression),
        );
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    #[DataProvider('functionExpressions')]
    public function expandsFunctions(string $expression, string $expected): void
    {
        self::assertSame(
            $expected,
            new VariableExpander([
                new Variable('COMMA', ','),
                new Variable('FUNCTION', 'subst'),
                new Variable('LIST', 'a b'),
            ])->expand($expression),
        );
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    #[DataProvider('conditionalExpressions')]
    public function expandsOnlyNeededConditionalArguments(string $expression, string $expected): void
    {
        self::assertSame(
            $expected,
            new VariableExpander([
                new Variable('CYCLE', '$(CYCLE)'),
                new Variable('SPACE', ' ', false),
                new Variable('NAME', 'if'),
            ])->expand($expression),
        );
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function preservesConditionalEvaluationOrderAndSkipsSideEffects(): void
    {
        $output = new FakeOutput();
        self::assertSame(
            'chosen',
            new VariableExpander([], $output)->expand(
                '$(if $(info condition)yes,$(or $(info first),$(info second)chosen,$(info skipped)),$(info skipped))',
            ),
        );
        self::assertSame(["condition\n", "first\n", "second\n"], $output->writes);
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    #[DataProvider('invalidFunctionExpressions')]
    public function rejectsInvalidFunctionArguments(string $expression): void
    {
        $this->expectException(MakefileErrorException::class);
        new VariableExpander([])->expand($expression);
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function rejectsMissingForeachArgumentsEvenForEmptyList(): void
    {
        $this->expectException(MakefileErrorException::class);
        new VariableExpander([])->expand('$(foreach x,)');
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function rejectsMissingIfArguments(): void
    {
        $this->expectException(MakefileErrorException::class);
        new VariableExpander([])->expand('$(if yes)');
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function restoresCallParametersAndMasksOuterArguments(): void
    {
        $expander = new VariableExpander([
            new Variable('1', 'global-one'),
            new Variable('2', 'global-two'),
            new Variable('pair', '$0:$1:$2'),
            new Variable('relay', '$(call pair,inner)|$1:$2'),
        ]);
        self::assertSame('pair:first:global-two', $expander->expand('$(call pair,first)'));
        self::assertSame('pair:inner:|outer:second', $expander->expand('$(call relay,outer,second)'));
        self::assertSame('global-one:global-two', $expander->expand('$1:$2'));
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function warningsReturnAnEmptyValueAndPreserveTheExpansionLocation(): void
    {
        $output = new FakeOutput();
        self::assertSame(
            'done',
            new VariableExpander(
                [new Variable('message', '$(foreach x,a,$(call warning,$x))')],
                $output,
                source: 'example.mk:7',
            )->expand('$(message)done'),
        );
        self::assertSame(['a'], $output->warnings);
        self::assertSame(['example.mk:7'], $output->warningSources);
    }
}
