<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Source;

use Tamiroh\Phmake\Makefile\Evaluation\Assignment;
use Tamiroh\Phmake\Parser\Syntax\ScopedAssignment;

use function explode;
use function ltrim;
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

    public function next(
        string $recipePrefix = "\t",
        bool $definition = false,
        bool $posix = false,
        bool $hasRule = true,
    ): ?string {
        if (!isset($this->lines[$this->offset])) {
            return null;
        }

        $this->lineNumber = $this->offset + 1;
        $line = $this->lines[$this->offset];
        $this->offset++;
        $recipe = !$definition && $this->isRecipe($line, $recipePrefix, $hasRule);
        while (
            ((strlen($line) - strlen(rtrim($line, characters: '\\'))) % 2) === 1
            && isset($this->lines[$this->offset])
        ) {
            $next = $this->lines[$this->offset];
            $this->offset++;
            if ($recipe) {
                $line .= "\n" . (str_starts_with($next, $recipePrefix) ? substr($next, offset: 1) : $next);
            } else {
                $line = ($posix ? substr($line, 0, -1) : rtrim(substr($line, 0, -1))) . ' ' . ltrim($next);
                $recipe = !$definition && $this->isRecipe($line, $recipePrefix, $hasRule);
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
        for ($index = 0; $index < strlen($line); $index++) {
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
