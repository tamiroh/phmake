<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

/**
 * Lossless syntax. Classification is lexical, not a promise of read-time meaning.
 * Children partition the original source, including comments and line endings.
 * Structured fields may also describe subranges (for example an inline recipe).
 */
abstract readonly class Node
{
    /**
     * @param list<Node> $children
     */
    public function __construct(
        public SourceSpan $span,
        public string $raw,
        public array $children = [],
    ) {}

    public function source(): string
    {
        if ($this->children === []) {
            return $this->raw;
        }
        $source = '';
        foreach ($this->children as $child) {
            $source .= $child->source();
        }
        return $source;
    }
}
