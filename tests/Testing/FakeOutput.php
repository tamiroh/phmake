<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Tamiroh\Phmake\Makefile\Output;

final class FakeOutput implements Output
{
    /** @var list<string> */
    public array $writes = [];

    /** @var list<string> */
    public array $lines = [];

    public function write(string $text): void
    {
        $this->writes[] = $text;
    }

    public function writeLine(string $line): void
    {
        $this->lines[] = $line;
    }
}
