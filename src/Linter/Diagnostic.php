<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Linter;

use Tamiroh\Phmake\Parser\Ast\SourceSpan;

final readonly class Diagnostic
{
    public function __construct(
        public string $rule,
        public string $message,
        public SourceSpan $span,
    ) {}
}
