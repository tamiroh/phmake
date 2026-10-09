<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

final readonly class DefineHeaderNode extends Node
{
    public function __construct(
        SourceSpan $span,
        string $raw,
        public AssignmentNode $assignment,
        public bool $skipWhenInactive,
    ) {
        parent::__construct($span, $raw);
    }
}
