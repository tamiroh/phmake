<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

use Override;

use function array_slice;

final readonly class RuleNode extends Node
{
    /**
     * Lexically adjacent recipes, including the inline recipe. Conditional directives
     * and runtime recipe prefixes can change their actual ownership.
     *
     * @var list<RecipeNode>
     */
    public array $recipes;

    /**
     * @param list<Node> $children
     */
    public function __construct(
        SourceSpan $span,
        string $raw,
        public string $header,
        public ?RecipeNode $inlineRecipe = null,
        public bool $exportAll = false,
        array $children = [],
    ) {
        parent::__construct($span, $raw, $children);
        $recipes = $inlineRecipe === null ? [] : [$inlineRecipe];
        foreach ($children as $child) {
            if ($child instanceof RecipeNode) {
                $recipes[] = $child;
            }
        }
        $this->recipes = $recipes;
    }

    /**
     * @return list<Node>
     */
    #[Override]
    public function syntaxChildren(): array
    {
        // The first source child is the raw header already represented by this node.
        $body = array_slice($this->children, 1);
        return $this->inlineRecipe === null ? $body : [$this->inlineRecipe, ...$body];
    }
}
