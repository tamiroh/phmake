<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Parser\Ast\AssignmentNode;
use Tamiroh\Phmake\Parser\Ast\ConditionalNode;
use Tamiroh\Phmake\Parser\Ast\DefineNode;
use Tamiroh\Phmake\Parser\Ast\DirectiveNode;
use Tamiroh\Phmake\Parser\Ast\IncludeNode;
use Tamiroh\Phmake\Parser\Ast\MakefileNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\RawNode;
use Tamiroh\Phmake\Parser\Ast\RecipeNode;
use Tamiroh\Phmake\Parser\Ast\RuleNode;
use Tamiroh\Phmake\Parser\Ast\SourceSpan;
use Tamiroh\Phmake\Parser\Ast\TargetAssignmentNode;
use Tamiroh\Phmake\Parser\Ast\TriviaNode;

use function array_pop;
use function count;
use function in_array;
use function ltrim;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function substr_count;
use function trim;

/**
 * Pure, lossless syntax parser. No filesystem or evaluation services are accepted.
 * State-dependent syntax remains lexical: the evaluator resolves logical lines and
 * recipe prefixes in reading order. Malformed/incomplete input is retained for tools.
 */
final readonly class MakefileParser
{
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
        return new MakefileNode(new SourceSpan($file, 0, $length, 1, 1, $line, $column), '', $this->blocks($nodes));
    }

    private function assignment(SourceSpan $span, string $raw, string $text): ?AssignmentNode
    {
        $modifiers = [];
        $matches = [];
        $text = ltrim($text);
        while (
            preg_match('/^(override|export|unexport|private)[ \t]+(?![ \t]*[:+?!=])(.*)$/s', $text, $matches) === 1
        ) {
            $modifiers[] = $matches[1];
            $text = ltrim($matches[2]);
        }
        $equal = $this->delimiter($text, '=');
        if ($equal === null) {
            return null;
        }
        $before = substr($text, 0, $equal);
        preg_match('/(:{1,3}|[!+?])?$/', $before, $matches);
        $operator = ($matches[1] ?? '') . '=';
        $name = rtrim(substr($text, 0, $equal + 1 - strlen($operator)));
        if (
            $name === ''
            || $this->delimiter($name, ':') !== null
            || preg_match('/^(define|override define|export define)\s/', $name) === 1
        ) {
            return null;
        }
        // Multi-word names and expansion-generated syntax are left unresolved.
        if ($this->delimiter($name, ' ') !== null || $this->delimiter($name, "\t") !== null) {
            return null;
        }
        return new AssignmentNode($span, $raw, $name, $operator, ltrim(substr($text, $equal + 1)), $modifiers);
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private function blocks(array $nodes): array
    {
        /** @var list<array{string, list<Node>}> $stack */
        $stack = [];
        $result = [];
        foreach ($nodes as $node) {
            $directive = $node instanceof DirectiveNode ? $node->directive : '';
            $insideDefine = $stack !== [] && $stack[count($stack) - 1][0] === 'define';
            $opens =
                $directive === 'define'
                || !$insideDefine && in_array($directive, ['ifeq', 'ifneq', 'ifdef', 'ifndef'], true);
            if ($opens) {
                $stack[] = [$directive, [$node]];
                continue;
            }
            if ($stack === []) {
                $result[] = $node;
                continue;
            }
            $index = count($stack) - 1;
            $current = $stack[$index];
            $current[1][] = $insideDefine ? new RawNode($node->span, $node->raw) : $node;
            $stack[$index] = $current;
            if ($insideDefine && $directive === 'endef' || !$insideDefine && $directive === 'endif') {
                $block = array_pop($stack);
                $children = $block[1];
                $first = $children[0]->span;
                $last = $node->span;
                $span = new SourceSpan(
                    $first->file,
                    $first->startOffset,
                    $last->endOffset,
                    $first->startLine,
                    $first->startColumn,
                    $last->endLine,
                    $last->endColumn,
                );
                $group = $insideDefine
                    ? new DefineNode($span, '', $children)
                    : new ConditionalNode($span, '', $children);
                if ($stack === []) {
                    $result[] = $group;
                } else {
                    $index = count($stack) - 1;
                    $parent = $stack[$index];
                    $parent[1][] = $group;
                    $stack[$index] = $parent;
                }
            }
        }
        // Retain unterminated constructs as syntax, leaving diagnostics to consumers.
        while ($stack !== []) {
            $block = array_pop($stack);
            if ($stack === []) {
                $result = [...$result, ...$block[1]];
            } else {
                $index = count($stack) - 1;
                $parent = $stack[$index];
                $parent[1] = [...$parent[1], ...$block[1]];
                $stack[$index] = $parent;
            }
        }
        return $result;
    }

    private function classify(SourceSpan $span, string $raw): Node
    {
        if (str_starts_with($raw, "\t")) {
            return new RecipeNode($span, $raw);
        }
        $text = rtrim($raw, "\r\n");
        $comment = $this->delimiter($text, '#');
        $text = $comment === null ? $text : substr($text, 0, $comment);
        if (trim($text) === '') {
            return new TriviaNode($span, $raw);
        }
        $assignment = $this->assignment($span, $raw, $text);
        if ($assignment !== null) {
            return $assignment;
        }
        $matches = [];
        if (preg_match('/^\s*(-?include|sinclude)(?:[ \t]+(.*)|$)/s', $text, $matches) === 1) {
            return new IncludeNode($span, $raw, $matches[1], $matches[2] ?? '');
        }
        if (
            preg_match(
                '/^\s*(?:(?:override|export|unexport|private)[ \t]+)*(define|endef|ifeq|ifneq|ifdef|ifndef|else|endif|undefine|export|unexport|vpath|-?load)(?:[ \t]+(.*)|$)/s',
                $text,
                $matches,
            ) === 1
        ) {
            return new DirectiveNode($span, $raw, $matches[1], $matches[2] ?? '');
        }
        $colon = $this->delimiter($text, ':');
        if ($colon !== null) {
            $value = substr($text, $colon + 1);
            $assignmentSpan = $this->tailSpan($span, $raw, $colon + 1);
            $assignment = $this->assignment($assignmentSpan, substr($raw, $colon + 1), $value);
            if ($assignment !== null) {
                return new TargetAssignmentNode($span, $raw, substr($text, 0, $colon), $assignment);
            }
            $semicolon = $this->delimiter($text, ';');
            if ($semicolon === null) {
                return new RuleNode($span, $raw, $text);
            }
            $recipeSpan = $this->tailSpan($span, $raw, $semicolon + 1);
            return new RuleNode(
                $span,
                $raw,
                substr($text, 0, $semicolon),
                new RecipeNode($recipeSpan, substr($raw, $semicolon + 1)),
            );
        }
        return new RawNode($span, $raw);
    }

    private function delimiter(string $text, string $delimiter): ?int
    {
        $depth = 0;
        for ($index = 0; $index < strlen($text); $index++) {
            $character = $text[$index];
            if ($character === '\\') {
                $index++;
                continue;
            }
            if (($character === '(' || $character === '{') && ($depth > 0 || $index > 0 && $text[$index - 1] === '$')) {
                $depth++;
            } elseif (($character === ')' || $character === '}') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && $character === $delimiter) {
                return $index;
            }
        }
        return null;
    }

    private function tailSpan(SourceSpan $span, string $raw, int $offset): SourceSpan
    {
        $prefix = substr($raw, 0, $offset);
        $newline = strrpos($prefix, "\n");
        return new SourceSpan(
            $span->file,
            $span->startOffset + $offset,
            $span->endOffset,
            $span->startLine + substr_count($prefix, "\n"),
            $newline === false ? $span->startColumn + $offset : strlen($prefix) - $newline,
            $span->endLine,
            $span->endColumn,
        );
    }
}
