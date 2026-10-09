<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

final readonly class ConditionalDirectiveNode extends Node
{
    public function __construct(
        SourceSpan $span,
        string $raw,
        public string $directive,
        public string $expression,
    ) {
        parent::__construct($span, $raw);
    }
}
