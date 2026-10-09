<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Parser\Ast\MakefileNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\RecipeNode;
use Tamiroh\Phmake\Parser\Ast\SourceSpan;
use Tamiroh\Phmake\Parser\Ast\TriviaNode;
use Tamiroh\Phmake\Parser\Syntax\BlockSyntax;
use Tamiroh\Phmake\Parser\Syntax\LineSyntax;

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
        return new MakefileNode(
            new SourceSpan($file, 0, $length, 1, 1, $line, $column),
            '',
            BlockSyntax::group($nodes),
        );
    }

    private function classify(SourceSpan $span, string $raw): Node
    {
        $line = rtrim($raw, "\r\n");
        return str_starts_with($raw, "\t")
            ? new RecipeNode($span, $raw, substr($line, 1))
            : LineSyntax::parse($span, $raw, $line);
    }
}
