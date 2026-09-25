<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;

final class ConditionalFunctionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expressions(): iterable
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
    #[DataProvider('expressions')]
    public function expandsOnlyNeededArguments(string $expression, string $expected): void
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
    public function preservesEvaluationOrderAndSkipsSideEffects(): void
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
}
