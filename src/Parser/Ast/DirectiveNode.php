<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

final readonly class DirectiveNode extends Node
{
    /**
     * @param list<string> $modifiers
     */
    public function __construct(
        SourceSpan $span,
        string $raw,
        public string $directive,
        public string $expression,
        public array $modifiers = [],
    ) {
        parent::__construct($span, $raw);
    }
}
