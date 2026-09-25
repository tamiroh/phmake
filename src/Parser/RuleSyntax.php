<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\Rule\DependencySyntax;
use Tamiroh\Phmake\Makefile\Rule\Pattern;
use Tamiroh\Phmake\Makefile\Rule\PrerequisiteExpression;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;

use function count;
use function ltrim;
use function rtrim;
use function str_ends_with;
use function strpbrk;
use function substr;

final class RuleSyntax
{
    /**
     * @throws ParseException
     */
    public static function parse(string $header, int $line, ?string $source, ?SourceFiles $files): Rule
    {
        $colon = DependencySyntax::delimiter($header, ':');
        if ($colon === null) {
            throw new ParseException($line, 'missing separator');
        }
        $targets = rtrim(substr($header, 0, $colon));
        $grouped = str_ends_with($targets, '&') && !str_ends_with($targets, '\\&');
        if ($grouped) {
            $targets = substr($targets, 0, -1);
        }
        $double = ($header[$colon + 1] ?? '') === ':';
        $dependencies = ltrim(substr($header, $colon + ($double ? 2 : 1)));
        $pattern = null;
        $second = DependencySyntax::delimiter($dependencies, ':');
        if ($second !== null) {
            $patterns = DependencySyntax::words(substr($dependencies, 0, $second));
            if ($patterns === []) {
                throw new ParseException($line, 'missing target pattern');
            }
            if (count($patterns) !== 1) {
                throw new ParseException($line, 'multiple target patterns');
            }
            $pattern = $patterns[0];
            if (!new Pattern($pattern)->hasWildcard()) {
                throw new ParseException($line, "target pattern contains no '%'");
            }
            $dependencies = substr($dependencies, $second + 1);
        }
        $order = DependencySyntax::delimiter($dependencies, '|');
        return new Rule(
            DependencySyntax::words($targets),
            new Prerequisites(
                self::paths($order === null ? $dependencies : substr($dependencies, 0, $order), $files),
                $order === null ? [] : self::paths(substr($dependencies, $order + 1), $files),
                [new PrerequisiteExpression($dependencies, source: $source)],
            ),
            $line,
            $double,
            $grouped,
            $pattern,
            $source,
        );
    }

    /**
     * @return list<string>
     */
    private static function paths(string $text, ?SourceFiles $files): array
    {
        $result = [];
        foreach (DependencySyntax::words($text) as $word) {
            $paths = strpbrk($word, '*?[') === false ? [] : $files?->matching($word) ?? [];
            $result = [...$result, ...($paths === [] ? [$word] : $paths)];
        }
        return $result;
    }
}
