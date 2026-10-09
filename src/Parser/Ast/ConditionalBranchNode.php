<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Ast;

final readonly class ConditionalBranchNode extends Node
{
    /**
     * @param list<Node> $body
     */
    public function __construct(
        public ConditionalDirectiveNode $header,
        public array $body,
    ) {
        $end = $header->span;
        foreach ($body as $node) {
            $end = $node->span;
        }
        parent::__construct($header->span->through($end), '', [$header, ...$body]);
    }
}
