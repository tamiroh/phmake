<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function count;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function str_contains;
use function strcspn;
use function strlen;
use function substr;

/**
 * @internal
 */
final class ExpansionSyntax
{
    /**
     * @pure
     *
     * @return list<string>
     */
    public static function arguments(string $text, int $limit, string $opening): array
    {
        $arguments = [];
        $start = 0;
        $depth = 0;
        $closing = $opening === '(' ? ')' : '}';
        $length = strlen($text);
        $stops = $opening . $closing . ',';
        for (
            $index = strcspn($text, $stops);
            $index < $length && count($arguments) < ($limit - 1);
            $index += 1 + strcspn($text, $stops, $index + 1)
        ) {
            if ($text[$index] === $opening) {
                $depth++;
            } elseif ($text[$index] === $closing) {
                $depth--;
            } elseif ($text[$index] === ',' && $depth === 0) {
                $arguments[] = substr($text, $start, $index - $start);
                $start = $index + 1;
            }
        }
        $arguments[] = substr($text, $start);
        return $arguments;
    }

    /**
     * @throws MakefileErrorException
     */
    public static function readReference(string $expression, int &$index, ?string $source = null): string
    {
        $opening = $expression[$index];
        $closing = $opening === '(' ? ')' : '}';
        $start = ++$index;
        $depth = 1;
        $length = strlen($expression);
        $stops = $opening . $closing;
        for (
            $index += strcspn($expression, $stops, $index);
            $index < $length;
            $index += 1 + strcspn($expression, $stops, $index + 1)
        ) {
            if ($expression[$index] === $opening) {
                $depth++;
            } elseif (--$depth === 0) {
                $reference = substr($expression, $start, $index - $start);
                return str_contains($reference, "\\\n")
                    ? preg_replace('/[ \t]*' . preg_quote("\\\n", '/') . '[ \t]*/', ' ', $reference) ?? $reference
                    : $reference;
            }
        }
        $index = $length;
        $matches = [];
        if (
            preg_match('/^([a-z]+)(?:[ \t\r\n\v\f]|$)/', substr($expression, $start), $matches) === 1
            && isset(Functions::ARGUMENT_COUNTS[$matches[1]])
        ) {
            throw new MakefileErrorException(
                "unterminated call to function '{$matches[1]}': missing '{$closing}'",
                $source,
            );
        }
        throw new MakefileErrorException('unterminated variable reference', $source);
    }
}
