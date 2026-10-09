<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

use Override;

final readonly class ConditionalNode extends Node
{
    /**
     * @param list<Node> $children
     * @param list<ConditionalBranchNode> $branches
     */
    public function __construct(
        SourceSpan $span,
        array $children,
        public array $branches,
        public ?ConditionalDirectiveNode $terminator,
    ) {
        parent::__construct($span, '', $children);
    }

    /**
     * @return list<Node>
     */
    #[Override]
    public function syntaxChildren(): array
    {
        return $this->terminator === null ? $this->branches : [...$this->branches, $this->terminator];
    }
}
