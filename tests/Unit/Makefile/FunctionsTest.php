<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;

final class FunctionsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function expressions(): iterable
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

    /** @return iterable<string, array{string}> */
    public static function invalidExpressions(): iterable
    {
        yield 'missing argument' => ['$(subst a,b)'];
        yield 'empty index' => ['$(word ,a)'];
        yield 'zero index' => ['$(word 0,a)'];
        yield 'negative index' => ['$(word -1,a)'];
        yield 'non-numeric index' => ['$(word 1x,a)'];
        yield 'overflowing index' => ['$(word 9999999999999999999,a)'];
        yield 'zero range start' => ['$(wordlist 0,1,a)'];
        yield 'negative range end' => ['$(wordlist 1,-1,a)'];
    }

    /** @throws MakefileErrorException */
    #[Test]
    #[DataProvider('expressions')]
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

    /** @throws MakefileErrorException */
    #[Test]
    #[DataProvider('invalidExpressions')]
    public function rejectsInvalidArguments(string $expression): void
    {
        $this->expectException(MakefileErrorException::class);
        new VariableExpander([])->expand($expression);
    }
}
