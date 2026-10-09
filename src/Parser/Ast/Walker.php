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
        foreach ($node->syntaxChildren() as $child) {
            $this->walk($child, $visit);
        }
    }
}
