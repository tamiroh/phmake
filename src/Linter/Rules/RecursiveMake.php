<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Linter\Rules;

use Override;
use Tamiroh\Phmake\Linter\Diagnostic;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\RecipeNode;

use function in_array;
use function ltrim;
use function min;
use function preg_match;
use function preg_replace;
use function str_contains;
use function strlen;
use function strrpos;
use function substr;

/**
 * Reports recipe commands that run make by name instead of through $(MAKE). Such a
 * command may start a different make, does not share the jobserver, and still runs
 * under -n, -t and -q. Only unquoted words in command position are considered.
 */
final readonly class RecursiveMake implements Rule
{
    private const array MAKES = ['make', 'gmake', 'phmake'];

    /**
     * Reserved words after which the next word is still in command position.
     */
    private const array KEYWORDS = ['!', '{', 'do', 'elif', 'else', 'if', 'then', 'until', 'while'];

    /**
     * Commands that run their remaining words as a command.
     */
    private const array WRAPPERS = ['command', 'env', 'exec'];

    private static function basename(string $word): string
    {
        $slash = strrpos($word, '/');
        return $slash === false ? $word : substr($word, $slash + 1);
    }

    private static function closingQuote(string $text, int $start): int
    {
        $quote = $text[$start];
        $length = strlen($text);
        for ($index = $start + 1; $index < $length; $index++) {
            if ($quote === '"' && $text[$index] === '\\') {
                $index++;
            } elseif ($text[$index] === $quote) {
                return $index;
            }
        }
        return $length - 1;
    }

    /**
     * Splits a recipe line into simple commands, each a list of words paired with
     * whether the word is plain text (no quoting, escapes or expansions).
     *
     * @return list<list<array{string, bool}>>
     */
    private static function commands(string $recipe): array
    {
        // The shell joins continued lines; make removes the recipe prefix after each.
        $text = preg_replace('/\\\\\r?\n\t?/', ' ', $recipe) ?? $recipe;
        $text = ltrim($text, "@+- \t");
        $commands = [];
        $words = [];
        $word = '';
        $plain = true;
        $started = false;
        $length = strlen($text);
        // A virtual newline past the end finishes the last word and command.
        for ($index = 0; $index <= $length; $index++) {
            $character = $index < $length ? $text[$index] : "\n";
            if ($character === "'" || $character === '"') {
                $end = self::closingQuote($text, $index);
                $word .= substr($text, $index, $end - $index + 1);
                $plain = false;
                $started = true;
                $index = $end;
            } elseif ($character === '\\') {
                $word .= substr($text, $index, 2);
                $plain = false;
                $started = true;
                $index = min($index + 1, $length - 1);
            } elseif ($character === '$') {
                $end = self::referenceEnd($text, $index);
                $word .= substr($text, $index, $end - $index + 1);
                $plain = false;
                $started = true;
                $index = $end;
            } elseif ($character === '#' && !$started) {
                while (($index + 1) < $length && $text[$index + 1] !== "\n") {
                    $index++;
                }
            } elseif (str_contains(" \t;&|()\n", $character)) {
                if ($started) {
                    $words[] = [$word, $plain];
                }
                $word = '';
                $plain = true;
                $started = false;
                if ($character !== ' ' && $character !== "\t" && $words !== []) {
                    $commands[] = $words;
                    $words = [];
                }
            } else {
                $word .= $character;
                $started = true;
            }
        }
        return $commands;
    }

    /**
     * Returns the command words that name make, in order.
     *
     * @return list<string>
     */
    private static function makes(string $recipe): array
    {
        $found = [];
        foreach (self::commands($recipe) as $words) {
            foreach ($words as [$word, $plain]) {
                if (in_array($word, self::KEYWORDS, true) && $plain) {
                    continue;
                }
                if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*=/', $word) === 1) {
                    continue;
                }
                if ($plain && in_array($word, self::WRAPPERS, true)) {
                    continue;
                }
                if ($plain && in_array(self::basename($word), self::MAKES, true)) {
                    $found[] = $word;
                }
                break;
            }
        }
        return $found;
    }

    /**
     * Finds the last byte of a make reference such as $(MAKE), ${MAKE}, $@ or $$.
     */
    private static function referenceEnd(string $text, int $start): int
    {
        $length = strlen($text);
        if (($start + 1) >= $length) {
            return $start;
        }
        $open = $text[$start + 1];
        if ($open !== '(' && $open !== '{') {
            return $start + 1;
        }
        $close = $open === '(' ? ')' : '}';
        $depth = 0;
        for ($index = $start + 1; $index < $length; $index++) {
            if ($text[$index] === $open) {
                $depth++;
            } elseif ($text[$index] === $close && --$depth === 0) {
                return $index;
            }
        }
        return $length - 1;
    }

    #[Override]
    public function inspect(Node $node): array
    {
        if (!$node instanceof RecipeNode) {
            return [];
        }
        $diagnostics = [];
        foreach (self::makes($node->expression) as $word) {
            $diagnostics[] = new Diagnostic(
                'recursive-make',
                "Use \$(MAKE) instead of '{$word}' to run make recursively",
                $node->span,
            );
        }
        return $diagnostics;
    }
}
