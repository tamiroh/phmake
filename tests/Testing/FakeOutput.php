<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Tamiroh\Phmake\Makefile\Output;

final class FakeOutput implements Output
{
    /** @var list<string> */
    public private(set) array $writes = [];

    /** @var list<string> */
    public private(set) array $lines = [];

    /** @var list<string> */
    public private(set) array $infos = [];

    /** @var list<string> */
    public private(set) array $warnings = [];

    public function writeInfo(string $message): void
    {
        $this->infos[] = $message;
    }

    public function writeWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function write(string $text): void
    {
        $this->writes[] = $text;
    }

    public function writeLine(string $line): void
    {
        $this->lines[] = $line;
    }
}
