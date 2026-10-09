<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

use Override;

/**
 * Syntax whose rule header can only be determined after expansion.
 */
final readonly class ExpressionNode extends Node
{
    public function __construct(
        SourceSpan $span,
        string $raw,
        public string $header,
        public ?RecipeNode $inlineRecipe = null,
    ) {
        parent::__construct($span, $raw);
    }

    /**
     * @return list<Node>
     */
    #[Override]
    public function syntaxChildren(): array
    {
        return $this->inlineRecipe === null ? [] : [$this->inlineRecipe];
    }
}
