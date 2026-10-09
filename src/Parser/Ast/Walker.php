<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

final readonly class Walker
{
    /**
     * @param callable(Node): void $visit
     */
    public function walk(Node $node, callable $visit): void
    {
        $visit($node);
        foreach ($node->children as $child) {
            $this->walk($child, $visit);
        }
        if (($node instanceof RuleNode || $node instanceof ExpressionNode) && $node->inlineRecipe !== null) {
            $this->walk($node->inlineRecipe, $visit);
        }
        if ($node instanceof TargetAssignmentNode) {
            $this->walk($node->assignment, $visit);
        }
    }
}
