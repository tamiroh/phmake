<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

final readonly class AssignmentNode extends Node
{
    /**
     * @param list<string> $modifiers
     */
    public function __construct(
        SourceSpan $span,
        string $raw,
        public string $name,
        public string $operator,
        public string $expression,
        public array $modifiers = [],
        public bool $exportAll = false,
    ) {
        parent::__construct($span, $raw);
    }
}
