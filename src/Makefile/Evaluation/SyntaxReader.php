<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use Tamiroh\Phmake\Parser\Ast;
use Tamiroh\Phmake\Parser\ParseException;
use Tamiroh\Phmake\Parser\Syntax\LineSyntax;

use function implode;
use function rtrim;
use function str_starts_with;
use function substr;

/**
 * Resolves read-state-dependent syntax, then exposes only syntax nodes to evaluation.
 */
final readonly class SyntaxReader
{
    private LineReader $lines;

    public function __construct(Ast\MakefileNode $makefile)
    {
        $this->lines = new LineReader($makefile);
    }

    /**
     * @throws ParseException
     *
     * @return array{string, ?Ast\DirectiveNode}
     */
    public function definition(Ast\DefineHeaderNode $header, string $prefix, bool $posix): array
    {
        $body = [];
        $depth = 1;
        while (($line = $this->lines->next($prefix, definition: true, posix: $posix)) !== null) {
            $boundary = str_starts_with($line, $prefix) ? null : LineSyntax::definitionBoundary($line);
            if ($boundary !== null) {
                if ($boundary[0] === 'define') {
                    $depth++;
                } elseif (--$depth === 0) {
                    return [
                        implode("\n", $body),
                        new Ast\DirectiveNode($this->lines->span(), $this->lines->raw(), 'endef', $boundary[1]),
                    ];
                }
            }
            $body[] = $line;
        }
        throw new ParseException($header->span->startLine, "missing 'endef', unterminated 'define'");
    }

    public function lineNumber(): int
    {
        return $this->lines->lineNumber;
    }

    public function next(string $prefix, bool $posix, bool $hasRule): ?Ast\Node
    {
        $line = $this->lines->next($prefix, posix: $posix, hasRule: $hasRule);
        if ($line === null) {
            return null;
        }
        $span = $this->lines->span();
        $raw = $this->lines->raw();
        if ($hasRule && str_starts_with($line, $prefix)) {
            return new Ast\RecipeNode($span, $raw, substr($line, 1));
        }
        $node = $this->lines->node;
        if (
            $node !== null
            && !$node instanceof Ast\RecipeNode
            && !$node instanceof Ast\RawNode
            && rtrim($node->raw, "\r\n") === $line
        ) {
            return $node;
        }
        // The only fallback: reclassify a logical line after contextual folding,
        // or after a recipe/define fragment acquires a different read-time meaning.
        return LineSyntax::parse($span, $raw, $line);
    }
}
