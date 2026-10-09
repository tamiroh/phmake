<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

final readonly class RuleNode extends Node
{
    public function __construct(
        SourceSpan $span,
        string $raw,
        public string $header,
        public ?RecipeNode $inlineRecipe = null,
    ) {
        parent::__construct($span, $raw);
    }
}
