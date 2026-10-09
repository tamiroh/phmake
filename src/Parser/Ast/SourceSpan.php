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
}
