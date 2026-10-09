<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Formatter;

use Tamiroh\Phmake\Parser\Ast;

use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Emits syntax in source order. Unresolved read-time state disables whitespace
 * changes for the rest of the file, including across conditional branches.
 */
final class Printer
{
    private bool $normalize = true;

    public function print(Ast\Node $node): string
    {
        if ($node instanceof Ast\MakefileNode) {
            return $this->sequence($node->children);
        }
        if ($node instanceof Ast\ConditionalNode) {
            return (
                $this->sequence($node->branches) . ($node->terminator === null ? '' : $this->print($node->terminator))
            );
        }
        if ($node instanceof Ast\ConditionalBranchNode) {
            return $this->print($node->header) . $this->sequence($node->body);
        }
        if ($node instanceof Ast\DefineNode) {
            // The body is literal text, not a list of active statements.
            return (
                $this->print($node->header)
                . $node->body->raw
                . ($node->terminator === null ? '' : $this->print($node->terminator))
            );
        }
        if (
            $node instanceof Ast\IncludeNode
            || $node instanceof Ast\ExpressionNode
            || str_contains($node->raw, '.RECIPEPREFIX')
            || str_contains($node->raw, '$')
        ) {
            $this->normalize = false;
        }
        if ($node instanceof Ast\AssignmentNode && $this->normalize) {
            return $this->assignment($node);
        }
        if ($node instanceof Ast\RuleNode && $node->children !== []) {
            return $this->sequence($node->children);
        }
        return $node->raw;
    }

    private function assignment(Ast\AssignmentNode $node): string
    {
        // Preserve the complete value/comment spelling and line ending. Only
        // simple unmodified names are rewritten; continuations remain opaque.
        $parts = [];
        if (
            $node->modifiers !== []
            || str_contains($node->raw, '\\')
            || preg_match(
                '/\A( *)([A-Za-z_][A-Za-z0-9_.-]*)[ \t]*(:::=|::=|:=|!=|\+=|\?=|=)[ \t]*([^\r\n]*)(\r?\n)?\z/',
                $node->raw,
                $parts,
            ) !== 1
        ) {
            return $node->raw;
        }
        /** @var array{non-falsy-string, string, non-falsy-string, '!='|'+='|':::='|'::='|':='|'='|'?=', string, 5?: non-falsy-string} $parts */
        if ($parts[2] !== $node->name || $parts[3] !== $node->operator) {
            return $node->raw;
        }
        if (!str_starts_with($parts[4], $node->expression)) {
            return $node->raw;
        }
        $comment = substr($parts[4], strlen($node->expression));
        if ($comment !== '' && !str_starts_with($comment, '#')) {
            return $node->raw;
        }
        return $parts[1] . $node->name . ' ' . $node->operator . ' ' . $node->expression . $comment . ($parts[5] ?? '');
    }

    /**
     * @param list<Ast\Node> $nodes
     */
    private function sequence(array $nodes): string
    {
        $source = '';
        foreach ($nodes as $node) {
            $source .= $this->print($node);
        }
        return $source;
    }
}
