<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;

final class VariableExpanderTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function expressions(): iterable
    {
        yield 'single character and automatic variables' => ['$A $@ $< $^', 'value output input input other'];
        yield 'escaped automatic variable' => ['$$@ $$$@', '$@ $output'];
        yield 'suffix substitution' => ['$(FILES:.lo=.o)', 'a.o dir/b.o other'];
        yield 'pattern substitution' => ['${FILES:%.lo=obj/%.o}', 'obj/a.o obj/dir/b.o other'];
        yield 'empty suffix' => ['$(FILES:=.bak)', 'a.lo.bak dir/b.lo.bak other.bak'];
        yield 'nested name and replacement' => ['$($(NAME):.lo=$(SUFFIX))', 'a.o dir/b.o other'];
        yield 'undefined reference' => ['$(UNDEFINED:.lo=.o)', ''];
    }

    /** @throws MakefileErrorException */
    #[Test]
    #[DataProvider('expressions')]
    public function expandsMakeReferences(string $expression, string $expected): void
    {
        self::assertSame(
            $expected,
            new VariableExpander([
                new Variable('A', 'value'),
                new Variable('@', 'output', false),
                new Variable('<', 'input', false),
                new Variable('^', 'input other', false),
                new Variable('FILES', 'a.lo  dir/b.lo other'),
                new Variable('NAME', 'FILES'),
                new Variable('SUFFIX', '.o'),
            ])->expand($expression),
        );
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function expandsRecursiveReferencesWithoutExpandingEscapedDollarsAgain(): void
    {
        $expander = new VariableExpander([
            new Variable('A', '$(B)'),
            new Variable('B', 'hello'),
            new Variable('FIXED', '$(B)', false),
        ]);

        self::assertSame(
            'hello hello $(B) ${B} $HOME ',
            $expander->expand('$(A) ${B} $(FIXED) $${B} $$HOME $(MISSING)'),
        );
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function rejectsUnterminatedReferences(): void
    {
        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageIsOrContains('Unterminated variable reference');
        new VariableExpander([])->expand('$(BROKEN');
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function reportsRecursiveVariableCycles(): void
    {
        $expander = new VariableExpander([new Variable('A', '$(B)'), new Variable('B', '$(A)')]);
        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageIsOrContains("Recursive variable `A'");

        $expander->expand('$(A)');
    }
}
