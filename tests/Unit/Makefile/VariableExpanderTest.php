<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;

final class VariableExpanderTest extends TestCase
{
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
    public function reportsRecursiveVariableCycles(): void
    {
        $expander = new VariableExpander([new Variable('A', '$(B)'), new Variable('B', '$(A)')]);
        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageIsOrContains("Recursive variable `A'");

        $expander->expand('$(A)');
    }
}
