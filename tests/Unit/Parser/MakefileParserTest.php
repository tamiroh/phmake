<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Command;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Parser\MakefileParser;

class MakefileParserTest extends TestCase
{
    #[Test]
    public function parsedAsExpected(): void
    {
        $makefile = new MakefileParser(<<<MAKEFILE
            GREETING = hello
            .PHONY: foo baz
            foo: bar baz
                echo "$(GREETING) foo"
            bar: qux
                echo "bar"
            baz:
                echo "baz"
            qux:
                echo "qux"

            MAKEFILE)->parse();

        $fooTarget = $makefile->targets[0];
        self::assertSame('foo', $fooTarget->name);
        self::assertTrue($fooTarget->isPhony);
        self::assertSame('bar', $fooTarget->dependencies[0]->name);
        self::assertSame('baz', $fooTarget->dependencies[1]->name);
        self::assertEquals([new Command('echo "$(GREETING) foo"')], $fooTarget->commands);

        $barTarget = $makefile->targets[1];
        self::assertSame('bar', $barTarget->name);
        self::assertFalse($barTarget->isPhony);
        self::assertSame('qux', $barTarget->dependencies[0]->name);
        self::assertEquals([new Command('echo "bar"')], $barTarget->commands);

        $bazTarget = $makefile->targets[2];
        self::assertSame('baz', $bazTarget->name);
        self::assertTrue($bazTarget->isPhony);
        self::assertEmpty($bazTarget->dependencies);
        self::assertEquals([new Command('echo "baz"')], $bazTarget->commands);

        $quxTarget = $makefile->targets[3];
        self::assertSame('qux', $quxTarget->name);
        self::assertFalse($quxTarget->isPhony);
        self::assertEmpty($quxTarget->dependencies);
        self::assertEquals([new Command('echo "qux"')], $quxTarget->commands);

        self::assertEquals([new Variable('GREETING', 'hello')], $makefile->variables);
    }
}
