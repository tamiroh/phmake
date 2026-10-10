<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Engine\Expansion;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Engine\Expansion\Functions;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\MakefileErrorException;

final class FunctionsTest extends TestCase
{
    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function distinguishesEmptyAndMissingArguments(): void
    {
        self::assertSame('', Functions::subst('', '', ''));
        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageMatches("/^insufficient number of arguments to function 'subst'$/");
        Functions::subst('', '');
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function errorDoesNotRequireASourceLocation(): void
    {
        $this->expectException(MakefileErrorException::class);
        Functions::error('stopped');
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function foreachRejectsMissingBodyEvenWithEmptyList(): void
    {
        self::assertSame('', Functions::foreach(new VariableExpander([]), [], 'x', '', ''));
        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageMatches("/^insufficient number of arguments \(2\) to function 'foreach'$/");
        Functions::foreach(new VariableExpander([]), [], 'x', '');
    }

    /**
     * @throws MakefileErrorException
     */
    #[Test]
    public function ifRejectsMissingBranchBeforeExpanding(): void
    {
        $this->expectException(MakefileErrorException::class);
        $this->expectExceptionMessageMatches("/^insufficient number of arguments to function 'if'$/");
        Functions::if(static function (string $argument): string {
            self::fail('Missing arguments must be rejected before expansion: ' . $argument);
        }, 'yes');
    }
}
