<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile\Evaluation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Evaluation\LineReader;

final class LineReaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function continuations(): iterable
    {
        yield 'prerequisites join with a single space' => ["foo.o: a.h \\\n  b.h \\\n c.h\n", 'foo.o: a.h b.h c.h'];
        yield 'an inline recipe on the first line keeps continuations' => [
            "all: ; echo one \\\n\techo two\n",
            "all: ; echo one \\\necho two",
        ];
        yield 'an inline recipe on a continuation keeps later continuations' => [
            "all: foo \\\n ; echo one \\\n two\n",
            "all: foo ; echo one \\\n two",
        ];
        yield 'a semicolon in a comment does not start a recipe' => [
            "all: foo # a; b \\\n c\n",
            'all: foo # a; b c',
        ];
        yield 'a semicolon in a target-specific variable does not start a recipe' => [
            "all: V = a \\\n ; b \\\n c\n",
            'all: V = a ; b c',
        ];
    }

    #[Test]
    #[DataProvider('continuations')]
    public function joinsContinuationLines(string $source, string $expected): void
    {
        self::assertSame($expected, new LineReader($source)->next());
    }
}
