<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use function explode;
use function ltrim;
use function preg_match;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class LineReader
{
    /** @var list<string> */
    private readonly array $lines;

    /** @var non-negative-int */
    private int $offset = 0;

    public private(set) int $lineNumber = 0;

    public function __construct(string $source)
    {
        $this->lines = explode("\n", str_replace("\r\n", replace: "\n", subject: $source));
    }

    public function next(string $recipePrefix = "\t"): ?string
    {
        if (!isset($this->lines[$this->offset])) {
            return null;
        }

        $this->lineNumber = $this->offset + 1;
        $line = $this->lines[$this->offset++];
        $recipe = $this->isRecipe($line, $recipePrefix);
        while (
            ((strlen($line) - strlen(rtrim($line, characters: '\\'))) % 2) === 1
            && isset($this->lines[$this->offset])
        ) {
            $next = $this->lines[$this->offset++];
            if ($recipe) {
                $line .= "\n" . (str_starts_with($next, $recipePrefix) ? substr($next, offset: 1) : $next);
            } else {
                $line = rtrim(substr($line, offset: 0, length: -1)) . ' ' . ltrim($next);
                $recipe = $this->isRecipe($line, $recipePrefix);
            }
        }

        return $line;
    }

    private function isRecipe(string $line, string $recipePrefix): bool
    {
        if (str_starts_with($line, $recipePrefix)) {
            return true;
        }
        if (preg_match('/^\s*[A-Za-z_.][A-Za-z0-9_.-]*\s*(:=|\+=|\?=|=)/', $line) === 1) {
            return false;
        }
        $hasColon = false;
        for ($index = 0; $index < strlen($line); $index++) {
            if ($line[$index] === '\\') {
                $index++;
            } elseif ($line[$index] === '#') {
                return false;
            } elseif ($line[$index] === ':') {
                $hasColon = true;
            } elseif ($line[$index] === ';') {
                return $hasColon;
            }
        }
        return false;
    }
}
