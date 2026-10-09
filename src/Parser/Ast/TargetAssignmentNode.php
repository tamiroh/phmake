<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

final readonly class TargetAssignmentNode extends Node
{
    public function __construct(
        SourceSpan $span,
        string $raw,
        public string $targets,
        public AssignmentNode $assignment,
    ) {
        parent::__construct($span, $raw);
    }
}
