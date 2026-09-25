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

final class ForeachTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expressions(): iterable
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
     * @throws MakefileErrorException
     */
    #[Test]
    public function expandsNameAndListOnceBeforeBody(): void
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
    #[DataProvider('expressions')]
    public function expandsWithTemporaryScope(string $expression, string $expected): void
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
    public function rejectsMissingArgumentsEvenForEmptyList(): void
    {
        $this->expectException(MakefileErrorException::class);
        new VariableExpander([])->expand('$(foreach x,)');
    }
}
