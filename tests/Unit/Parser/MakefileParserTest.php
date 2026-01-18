<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Parser\MakefileParser;

class MakefileParserTest extends TestCase
{
    #[Test]
    public function parsedAsExpected(): void
    {
        $makefile = new MakefileParser(<<<MAKEFILE
            foo: bar baz
                echo "foo"
            bar: qux
                echo "bar"
            baz:
                echo "baz"
            qux:
                echo "qux"

            MAKEFILE)->parse();

        $fooTarget = $makefile->targets[0];
        $this->assertSame('foo', $fooTarget->name);
        $this->assertSame('bar', $fooTarget->dependencies[0]->name);
        $this->assertSame('baz', $fooTarget->dependencies[1]->name);

        $barTarget = $makefile->targets[1];
        $this->assertSame('bar', $barTarget->name);
        $this->assertSame('qux', $barTarget->dependencies[0]->name);

        $bazTarget = $makefile->targets[2];
        $this->assertSame('baz', $bazTarget->name);
        $this->assertEmpty($bazTarget->dependencies);

        $quxTarget = $makefile->targets[3];
        $this->assertSame('qux', $quxTarget->name);
        $this->assertEmpty($quxTarget->dependencies);
    }
}
