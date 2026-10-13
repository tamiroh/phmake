<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Parser\Ast\ConditionalBranchNode;
use Tamiroh\Phmake\Parser\Ast\ConditionalDirectiveNode;
use Tamiroh\Phmake\Parser\Ast\ConditionalNode;
use Tamiroh\Phmake\Parser\Ast\DefineHeaderNode;
use Tamiroh\Phmake\Parser\Ast\DefineNode;
use Tamiroh\Phmake\Parser\Ast\DirectiveNode;
use Tamiroh\Phmake\Parser\Ast\MakefileNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\RawNode;
use Tamiroh\Phmake\Parser\Ast\RecipeNode;
use Tamiroh\Phmake\Parser\Ast\RuleNode;
use Tamiroh\Phmake\Parser\Ast\SourceSpan;
use Tamiroh\Phmake\Parser\Ast\TriviaNode;

use function array_pop;
use function array_slice;
use function count;
use function in_array;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Pure, lossless syntax parser. No filesystem or evaluation services are accepted.
 * State-dependent syntax remains lexical: the evaluator resolves logical lines and
 * recipe prefixes in reading order. Malformed/incomplete input is retained for tools.
 */
final readonly class MakefileParser
{
    /**
     * @param non-empty-list<Node> $nodes
     */
    private static function conditional(array $nodes, ?ConditionalDirectiveNode $terminator): ConditionalNode
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
            if ($node instanceof ConditionalDirectiveNode) {
                if ($header !== null) {
                    $branches[] = new ConditionalBranchNode($header, $body);
                }
                $header = $node;
                $body = [];
            } else {
                $body[] = $node;
            }
        }
        if ($header !== null) {
            $branches[] = new ConditionalBranchNode($header, $body);
        }
        return new ConditionalNode($first->through($end), $nodes, $branches, $terminator);
    }

    /**
     * @param list<Node> $nodes
     */
    private static function definition(DefineHeaderNode $header, array $nodes, int &$index): DefineNode
    {
        $depth = 1;
        $text = '';
        $endOffset = $header->span->endOffset;
        $endLine = $header->span->endLine;
        $endColumn = $header->span->endColumn;
        $terminator = null;
        while (($node = $nodes[++$index] ?? null) !== null) {
            $boundary = str_starts_with($node->raw, "\t") ? null : LineParser::definitionBoundary($node->raw);
            if ($boundary !== null) {
                if ($boundary[0] === 'define') {
                    $depth++;
                } elseif (--$depth === 0) {
                    $terminator = new DirectiveNode($node->span, $node->raw, 'endef', $boundary[1]);
                    break;
                }
            }
            $text .= $node->raw;
            $endOffset = $node->span->endOffset;
            $endLine = $node->span->endLine;
            $endColumn = $node->span->endColumn;
        }
        $span = new SourceSpan(
            $header->span->file,
            $header->span->endOffset,
            $endOffset,
            $header->span->endLine,
            $header->span->endColumn,
            $endLine,
            $endColumn,
        );
        return new DefineNode($header, new RawNode($span, $text), $terminator);
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function group(array $nodes): array
    {
        /** @var list<non-empty-list<Node>> $stack */
        $stack = [];
        $result = [];
        for ($index = 0; $index < count($nodes); $index++) {
            $node = $nodes[$index];
            if ($node instanceof DefineHeaderNode) {
                $node = self::definition($node, $nodes, $index);
            }
            if ($node instanceof ConditionalDirectiveNode) {
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
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function rules(array $nodes): array
    {
        $result = [];
        for ($index = 0; $index < count($nodes); $index++) {
            $node = $nodes[$index];
            if (!$node instanceof RuleNode) {
                $result[] = $node;
                continue;
            }
            $lastRecipe = $index;
            $end = $node->span;
            for ($next = $index + 1; $next < count($nodes); $next++) {
                $following = $nodes[$next];
                if ($following instanceof RecipeNode) {
                    $lastRecipe = $next;
                    $end = $following->span;
                } elseif (!$following instanceof TriviaNode) {
                    break;
                }
            }
            if ($lastRecipe === $index) {
                $result[] = $node;
                continue;
            }
            $body = array_slice($nodes, $index + 1, $lastRecipe - $index);
            $result[] = new RuleNode(
                $node->span->through($end),
                '',
                $node->header,
                $node->inlineRecipe,
                $node->exportAll,
                [new RawNode($node->span, $node->raw), ...$body],
            );
            $index = $lastRecipe;
        }
        return $result;
    }

    public function parse(string $source, string $file = '<input>'): MakefileNode
    {
        $nodes = [];
        $offset = 0;
        $line = 1;
        $column = 1;
        $length = strlen($source);
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $nodes[] = new TriviaNode(new SourceSpan($file, 0, 3, 1, 1, 1, 4), substr($source, 0, 3));
            $offset = 3;
            $column = 4;
        }
        while ($offset < $length) {
            $start = $offset;
            $startLine = $line;
            $startColumn = $column;
            do {
                $newline = strpos($source, "\n", $offset);
                $end = $newline === false ? $length : $newline + 1;
                $physical = substr($source, $offset, $end - $offset);
                $text = rtrim($physical, "\r\n");
                $continued = ((strlen($text) - strlen(rtrim($text, '\\'))) % 2) === 1;
                $offset = $end;
                if ($newline === false) {
                    $column += strlen($physical);
                } else {
                    $line++;
                    $column = 1;
                }
            } while ($continued && $offset < $length);
            $span = new SourceSpan($file, $start, $offset, $startLine, $startColumn, $line, $column);
            $raw = substr($source, $start, $offset - $start);
            // Folding is .POSIX- and recipe-dependent; do not normalize continuations.
            $nodes[] = $this->classify($span, $raw);
        }
        return new MakefileNode(new SourceSpan($file, 0, $length, 1, 1, $line, $column), '', self::group($nodes));
    }

    private function classify(SourceSpan $span, string $raw): Node
    {
        $line = rtrim($raw, "\r\n");
        return str_starts_with($raw, "\t")
            ? new RecipeNode($span, $raw, substr($line, 1))
            : LineParser::parse($span, $raw, $line);
    }
}
