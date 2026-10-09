<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

/**
 * The body is literal source, including nested define text; it is not executed here.
 */
final readonly class DefineNode extends Node
{
    public function __construct(
        public DefineHeaderNode $header,
        public RawNode $body,
        public ?DirectiveNode $terminator,
    ) {
        parent::__construct(
            $header->span->through(($terminator ?? $body)->span),
            '',
            $terminator === null ? [$header, $body] : [$header, $body, $terminator],
        );
    }
}
