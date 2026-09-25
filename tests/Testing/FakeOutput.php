<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Override;
use Tamiroh\Phmake\Makefile\IO\Output;

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

    /** @var list<?string> */
    public private(set) array $warningSources = [];

    #[Override]
    public function write(string $text): void
    {
        $this->writes[] = $text;
    }

    #[Override]
    public function writeInfo(string $message): void
    {
        $this->infos[] = $message;
    }

    #[Override]
    public function writeLine(string $line): void
    {
        $this->lines[] = $line;
    }

    #[Override]
    public function writeWarning(string $message, ?string $source = null): void
    {
        $this->warnings[] = $message;
        $this->warningSources[] = $source;
    }
}
