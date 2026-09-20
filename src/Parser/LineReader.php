<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

final class LineReader
{
    /** @var list<string> */
    private readonly array $lines;

    private int $offset = 0;

    public private(set) int $lineNumber = 0;

    public function __construct(string $source)
    {
        $this->lines = explode("\n", str_replace("\r\n", "\n", $source));
    }

    private function isRecipe(string $line): bool
    {
        if (str_starts_with($line, "\t")) {
            return true;
        }
        if (preg_match('/^\s*[A-Za-z_][A-Za-z0-9_]*\s*(:=|=)/', $line) === 1) {
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

    public function next(): ?string
    {
        if (!isset($this->lines[$this->offset])) {
            return null;
        }

        $this->lineNumber = $this->offset + 1;
        $line = $this->lines[$this->offset++];
        $recipe = $this->isRecipe($line);
        while (((strlen($line) - strlen(rtrim($line, '\\'))) % 2) === 1 && isset($this->lines[$this->offset])) {
            $next = $this->lines[$this->offset++];
            if ($recipe) {
                $line .= "\n" . (str_starts_with($next, "\t") ? substr($next, 1) : $next);
            } else {
                $line = rtrim(substr($line, 0, -1)) . ' ' . ltrim($next);
                $recipe = $this->isRecipe($line);
            }
        }

        return $line;
    }
}
