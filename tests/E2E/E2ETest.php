<?php

namespace Tamiroh\Phmake\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Tests\Testing\Sandbox;

final class E2ETest extends TestCase
{
    #[Test]
    #[DataProvider('provideMakefiles')]
    public function runAsExpected(string $makefile, string $arguments, string $expectedOutput): void
    {
        $sandbox = Sandbox::create()->placeMakefile($makefile);
        $result = $sandbox->runPhMake($arguments);

        $this->assertSame($expectedOutput, $result);
    }

    /**
     * @return iterable<array{string, string, string}>
     */
    public static function provideMakefiles(): iterable
    {
        yield 'default target' => [
            <<<'MAKEFILE'
foo:
    echo 'foo'
bar:
    echo 'bar'
MAKEFILE,
            '',
            "echo 'foo'\nfoo\n",
        ];

        yield 'foo target' => [
            <<<'MAKEFILE'
foo:
    echo 'foo'
bar:
    echo 'bar'
MAKEFILE,
            'foo',
            "echo 'foo'\nfoo\n",
        ];

        yield 'two targets' => [
            <<<'MAKEFILE'
foo:
    echo 'foo'
bar:
    echo 'bar'
MAKEFILE,
            'bar foo',
            "echo 'bar'\nbar\necho 'foo'\nfoo\n",
        ];

        yield 'invalid target' => [
            <<<'MAKEFILE'
foo:
    echo 'foo'
bar:
    echo 'bar'
MAKEFILE,
            'baz',
            "phmake: *** No rule to make target `baz'.  Stop.\n",
        ];
    }
}