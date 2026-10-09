<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Source;

use Tamiroh\Phmake\Parser\Ast\MakefileNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\SourceSpan;
use Tamiroh\Phmake\Parser\Syntax\AssignmentSyntax;
use Tamiroh\Phmake\Parser\Syntax\LineSyntax;

use function array_slice;
use function count;
use function explode;
use function implode;
use function ltrim;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strcspn;
use function strlen;
use function substr;

final class LineReader
{
    /** @var list<string> */
    private readonly array $lines;

    /** @var list<string> */
    private readonly array $originalLines;

    /** @var list<int> */
    private readonly array $offsets;

    private readonly string $file;

    private readonly int $endOffset;

    /** @var array<int, Node> */
    private readonly array $nodes;

    /** The lexical node for an unchanged single physical line, if available. */
    public private(set) ?Node $node = null;

    /** @var non-negative-int */
    private int $offset = 0;

    public private(set) int $lineNumber = 0;

    public function __construct(string|MakefileNode $source)
    {
        if ($source instanceof MakefileNode) {
            [$original, $this->nodes, $offset] = self::syntaxLines($source);
            $this->file = $source->span->file;
        } else {
            $original = explode("\n", $source);
            $this->nodes = [];
            $this->file = '<input>';
            $offset = 0;
        }
        $this->originalLines = $original;
        $lines = [];
        $offsets = [];
        foreach ($original as $index => $line) {
            $offsets[] = $offset;
            $terminated = $index < (count($original) - 1);
            $lines[] = $terminated && str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
            $offset += strlen($line) + ($terminated ? 1 : 0);
        }
        $this->lines = $lines;
        $this->offsets = $offsets;
        $this->endOffset = $offset;
    }

    /**
     * @return iterable<Node>
     */
    private static function leaves(Node $node): iterable
    {
        if ($node->children === []) {
            yield $node;
            return;
        }
        foreach ($node->children as $child) {
            yield from self::leaves($child);
        }
    }

    /**
     * @return array{list<string>, array<int, Node>, int}
     */
    private static function syntaxLines(MakefileNode $source): array
    {
        $lines = [];
        $pending = '';
        $nodes = [];
        $first = true;
        $offset = 0;
        foreach (self::leaves($source) as $node) {
            $raw = $node->raw;
            if ($first && str_starts_with($raw, "\xEF\xBB\xBF")) {
                $raw = substr($raw, 3);
                $offset = 3;
            }
            $first = false;
            if ($raw === '') {
                continue;
            }
            $index = count($lines);
            // Only complete single-line leaves may bypass contextual syntax parsing.
            if ($pending === '' && !str_contains(rtrim($raw, "\n"), "\n")) {
                $nodes[$index] = $node;
            } else {
                unset($nodes[$index]);
            }
            foreach (explode("\n", $raw) as $part => $text) {
                if ($part > 0) {
                    $lines[] = $pending;
                    $pending = '';
                }
                $pending .= $text;
            }
        }
        $lines[] = $pending;
        return [$lines, $nodes, $offset];
    }

    public function next(
        string $recipePrefix = "\t",
        bool $definition = false,
        bool $posix = false,
        bool $hasRule = true,
    ): ?string {
        $this->node = null;
        if (!isset($this->lines[$this->offset])) {
            return null;
        }

        $this->node = $this->nodes[$this->offset] ?? null;
        $this->lineNumber = $this->offset + 1;
        $line = $this->lines[$this->offset];
        $this->offset++;
        $recipe = !$definition && $this->isRecipe($line, $recipePrefix, $hasRule);
        while (
            ((strlen($line) - strlen(rtrim($line, characters: '\\'))) % 2) === 1
            && isset($this->lines[$this->offset])
        ) {
            $this->node = null;
            $next = $this->lines[$this->offset];
            $this->offset++;
            if ($recipe) {
                $line .= "\n" . (str_starts_with($next, $recipePrefix) ? substr($next, offset: 1) : $next);
            } else {
                $line = ($posix ? substr($line, 0, -1) : rtrim(substr($line, 0, -1))) . ' ' . ltrim($next);
                $recipe = !$definition && str_contains($next, ';') && $this->isRecipe($line, $recipePrefix, $hasRule);
            }
        }

        return $line;
    }

    public function raw(): string
    {
        $raw = implode("\n", array_slice(
            $this->originalLines,
            $this->lineNumber - 1,
            $this->offset - $this->lineNumber + 1,
        ));
        return $raw . ($this->offset < count($this->lines) ? "\n" : '');
    }

    public function span(): SourceSpan
    {
        $start = $this->lineNumber - 1;
        $endedLine = $this->offset < count($this->lines);
        $last = $this->originalLines[$this->offset - 1] ?? '';
        return new SourceSpan(
            $this->file,
            $this->offsets[$start] ?? 0,
            $this->offsets[$this->offset] ?? $this->endOffset,
            $this->lineNumber,
            $start === 0 ? ($this->offsets[0] ?? 0) + 1 : 1,
            $endedLine ? $this->offset + 1 : $this->offset,
            $endedLine ? 1 : strlen($last) + 1,
        );
    }

    private function isRecipe(string $line, string $recipePrefix, bool $hasRule): bool
    {
        if ($hasRule && str_starts_with($line, $recipePrefix)) {
            return true;
        }
        if (AssignmentSyntax::parse($line) !== null) {
            return false;
        }
        $hasColon = false;
        $length = strlen($line);
        for ($index = strcspn($line, '\\#:;'); $index < $length; $index += 1 + strcspn($line, '\\#:;', $index + 1)) {
            if ($line[$index] === '\\') {
                $index++;
            } elseif ($line[$index] === '#') {
                return false;
            } elseif ($line[$index] === ':') {
                $value = substr($line, $index + 1);
                [$value] = LineSyntax::modifiers($value, scoped: true);
                if (AssignmentSyntax::parse($value) !== null) {
                    return false;
                }
                $hasColon = true;
            } elseif ($line[$index] === ';') {
                return $hasColon;
            }
        }
        return false;
    }
}
