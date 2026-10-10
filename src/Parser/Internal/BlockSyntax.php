<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Internal;

use Tamiroh\Phmake\Parser\Ast;
use Tamiroh\Phmake\Parser\Syntax\LineSyntax;

use function array_pop;
use function array_slice;
use function count;
use function in_array;
use function str_starts_with;

/**
 * Source-only grouping. Unclosed blocks retain their structure and a null terminator.
 */
final readonly class BlockSyntax
{
    /**
     * @param list<Ast\Node> $nodes
     *
     * @return list<Ast\Node>
     */
    public static function group(array $nodes): array
    {
        /** @var list<non-empty-list<Ast\Node>> $stack */
        $stack = [];
        $result = [];
        for ($index = 0; $index < count($nodes); $index++) {
            $node = $nodes[$index];
            if ($node instanceof Ast\DefineHeaderNode) {
                $node = self::definition($node, $nodes, $index);
            }
            if ($node instanceof Ast\ConditionalDirectiveNode) {
                if (in_array($node->directive, ['ifeq', 'ifneq', 'ifdef', 'ifndef'], true)) {
                    $stack[] = [$node];
                    continue;
                }
                if ($node->directive === 'endif' && $stack !== []) {
                    $body = array_pop($stack);
                    $body[] = $node;
                    $node = self::conditional($body, $node);
                }
            }
            if ($stack === []) {
                $result[] = $node;
            } else {
                $last = count($stack) - 1;
                $body = $stack[$last];
                $body[] = $node;
                $stack[$last] = $body;
            }
        }
        while ($stack !== []) {
            $node = self::conditional(array_pop($stack), null);
            if ($stack === []) {
                $result[] = $node;
            } else {
                $last = count($stack) - 1;
                $body = $stack[$last];
                $body[] = $node;
                $stack[$last] = $body;
            }
        }
        return self::rules($result);
    }

    /**
     * @param non-empty-list<Ast\Node> $nodes
     */
    private static function conditional(array $nodes, ?Ast\ConditionalDirectiveNode $terminator): Ast\ConditionalNode
    {
        $first = $nodes[0]->span;
        $nodes = self::rules($nodes);
        $branches = [];
        $header = null;
        $body = [];
        $end = $first;
        foreach ($nodes as $node) {
            $end = $node->span;
            if ($node === $terminator) {
                continue;
            }
            if ($node instanceof Ast\ConditionalDirectiveNode) {
                if ($header !== null) {
                    $branches[] = new Ast\ConditionalBranchNode($header, $body);
                }
                $header = $node;
                $body = [];
            } else {
                $body[] = $node;
            }
        }
        if ($header !== null) {
            $branches[] = new Ast\ConditionalBranchNode($header, $body);
        }
        return new Ast\ConditionalNode($first->through($end), $nodes, $branches, $terminator);
    }

    /**
     * @param list<Ast\Node> $nodes
     */
    private static function definition(Ast\DefineHeaderNode $header, array $nodes, int &$index): Ast\DefineNode
    {
        $depth = 1;
        $text = '';
        $endOffset = $header->span->endOffset;
        $endLine = $header->span->endLine;
        $endColumn = $header->span->endColumn;
        $terminator = null;
        while (($node = $nodes[++$index] ?? null) !== null) {
            $boundary = str_starts_with($node->raw, "\t") ? null : LineSyntax::definitionBoundary($node->raw);
            if ($boundary !== null) {
                if ($boundary[0] === 'define') {
                    $depth++;
                } elseif (--$depth === 0) {
                    $terminator = new Ast\DirectiveNode($node->span, $node->raw, 'endef', $boundary[1]);
                    break;
                }
            }
            $text .= $node->raw;
            $endOffset = $node->span->endOffset;
            $endLine = $node->span->endLine;
            $endColumn = $node->span->endColumn;
        }
        $span = new Ast\SourceSpan(
            $header->span->file,
            $header->span->endOffset,
            $endOffset,
            $header->span->endLine,
            $header->span->endColumn,
            $endLine,
            $endColumn,
        );
        return new Ast\DefineNode($header, new Ast\RawNode($span, $text), $terminator);
    }

    /**
     * @param list<Ast\Node> $nodes
     *
     * @return list<Ast\Node>
     */
    private static function rules(array $nodes): array
    {
        $result = [];
        for ($index = 0; $index < count($nodes); $index++) {
            $node = $nodes[$index];
            if (!$node instanceof Ast\RuleNode) {
                $result[] = $node;
                continue;
            }
            $lastRecipe = $index;
            $end = $node->span;
            for ($next = $index + 1; $next < count($nodes); $next++) {
                $following = $nodes[$next];
                if ($following instanceof Ast\RecipeNode) {
                    $lastRecipe = $next;
                    $end = $following->span;
                } elseif (!$following instanceof Ast\TriviaNode) {
                    break;
                }
            }
            if ($lastRecipe === $index) {
                $result[] = $node;
                continue;
            }
            $body = array_slice($nodes, $index + 1, $lastRecipe - $index);
            $result[] = new Ast\RuleNode(
                $node->span->through($end),
                '',
                $node->header,
                $node->inlineRecipe,
                $node->exportAll,
                [new Ast\RawNode($node->span, $node->raw), ...$body],
            );
            $index = $lastRecipe;
        }
        return $result;
    }
}
