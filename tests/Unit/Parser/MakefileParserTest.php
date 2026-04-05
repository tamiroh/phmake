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
        $this->assertSame('foo', $fooTarget->name);
        $this->assertTrue($fooTarget->isPhony);
        $this->assertSame('bar', $fooTarget->dependencies[0]->name);
        $this->assertSame('baz', $fooTarget->dependencies[1]->name);
        $this->assertEquals([new Command('echo "$(GREETING) foo"')], $fooTarget->commands);

        $barTarget = $makefile->targets[1];
        $this->assertSame('bar', $barTarget->name);
        $this->assertFalse($barTarget->isPhony);
        $this->assertSame('qux', $barTarget->dependencies[0]->name);
        $this->assertEquals([new Command('echo "bar"')], $barTarget->commands);

        $bazTarget = $makefile->targets[2];
        $this->assertSame('baz', $bazTarget->name);
        $this->assertTrue($bazTarget->isPhony);
        $this->assertEmpty($bazTarget->dependencies);
        $this->assertEquals([new Command('echo "baz"')], $bazTarget->commands);

        $quxTarget = $makefile->targets[3];
        $this->assertSame('qux', $quxTarget->name);
        $this->assertFalse($quxTarget->isPhony);
        $this->assertEmpty($quxTarget->dependencies);
        $this->assertEquals([new Command('echo "qux"')], $quxTarget->commands);

        $this->assertEquals([new Variable('GREETING', 'hello')], $makefile->variables);
    }
}
