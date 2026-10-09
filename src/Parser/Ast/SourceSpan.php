<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

/**
 * Byte offsets are zero-based and end-exclusive; lines and byte columns are one-based.
 */
final readonly class SourceSpan
{
    public function __construct(
        public string $file,
        public int $startOffset,
        public int $endOffset,
        public int $startLine,
        public int $startColumn,
        public int $endLine,
        public int $endColumn,
    ) {}

    public function through(self $end): self
    {
        return new self(
            $this->file,
            $this->startOffset,
            $end->endOffset,
            $this->startLine,
            $this->startColumn,
            $end->endLine,
            $end->endColumn,
        );
    }
}
