<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Source;

use Tamiroh\Phmake\Makefile\Variable\Assignment;
use Tamiroh\Phmake\Parser\Ast\MakefileNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Syntax\ScopedAssignment;

use function count;
use function explode;
use function ltrim;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strcspn;
use function strlen;
use function substr;

final class LineReader
{
    /** @var list<string> */
    private readonly array $lines;

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
            [$this->lines, $this->nodes] = self::syntaxLines($source);
            return;
        }
        $this->lines = explode("\n", str_replace("\r\n", replace: "\n", subject: $source));
        $this->nodes = [];
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
     * @return array{list<string>, array<int, Node>}
     */
    private static function syntaxLines(MakefileNode $source): array
    {
        $lines = [];
        $pending = '';
        $nodes = [];
        $first = true;
        foreach (self::leaves($source) as $node) {
            $raw = $node->raw;
            if ($first && str_starts_with($raw, "\xEF\xBB\xBF")) {
                $raw = substr($raw, 3);
            }
            $first = false;
            if ($raw === '') {
                continue;
            }
            $raw = str_replace("\r\n", "\n", $raw);
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
        return [$lines, $nodes];
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

    private function isRecipe(string $line, string $recipePrefix, bool $hasRule): bool
    {
        if ($hasRule && str_starts_with($line, $recipePrefix)) {
            return true;
        }
        if (Assignment::parse($line) !== null) {
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
                if (ScopedAssignment::parse($value) !== null) {
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
