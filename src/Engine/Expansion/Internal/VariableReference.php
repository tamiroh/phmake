<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Expansion\Internal;

use Tamiroh\Phmake\Engine\Expansion\Functions;
use Tamiroh\Phmake\Engine\Expansion\UndefinedVariable;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\Pattern;

use function explode;
use function implode;
use function preg_match;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const PHP_INT_MAX;
use const PREG_SPLIT_NO_EMPTY;

/**
 * @internal
 */
final class VariableReference
{
    /**
     * @param list<string> $expanding
     *
     * @throws MakefileErrorException
     */
    public static function expand(
        VariableExpander $expander,
        string $reference,
        array $expanding,
        string $opening,
    ): string {
        $matches = [];
        if (preg_match('/^([A-Za-z0-9_.-]+)[ \t\r\n\v\f]+/', $reference, $matches) === 1) {
            /** @var array{non-falsy-string, non-empty-string} $matches */
            $argumentCount =
                Functions::ARGUMENT_COUNTS[$matches[1]]
                ?? $expander->context->loadedObjects->functions[$matches[1]]->maximum
                ?? null;
            if ($argumentCount === 0) {
                $argumentCount = PHP_INT_MAX;
            }
            if ($argumentCount !== null) {
                try {
                    return (
                        $expander->invokeFunction(
                            $matches[1],
                            ExpansionSyntax::arguments(
                                substr($reference, strlen($matches[0])),
                                $argumentCount,
                                $opening,
                            ),
                            $expanding,
                        ) ?? ''
                    );
                } catch (MakefileErrorException $error) {
                    if (!$error->contextual) {
                        throw $error;
                    }
                    throw new MakefileErrorException(
                        $error->getMessage(),
                        $error->source ?? $expander->definitionSource ?? $expander->source,
                    );
                }
            }
        }
        $reference = $expander->expand($reference, $expanding);
        $colon = strpos($reference, ':');
        if ($colon === false || !str_contains(substr($reference, $colon + 1), '=')) {
            return self::value($expander, $reference, $expanding);
        }
        [$from, $to] = explode('=', substr($reference, $colon + 1), 2);
        $words = preg_split(
            '/\s+/',
            trim(self::value($expander, substr($reference, 0, $colon), $expanding)),
            -1,
            PREG_SPLIT_NO_EMPTY,
        );
        $result = [];
        foreach ($words === false ? [] : $words as $word) {
            if (str_contains($from, '%')) {
                $stem = new Pattern($from)->match($word);
                $result[] = $stem === null ? $word : new Pattern($to)->substitute($stem);
            } else {
                $result[] = str_ends_with($word, $from) ? substr($word, 0, strlen($word) - strlen($from)) . $to : $word;
            }
        }
        return implode(' ', $result);
    }

    /**
     * @param list<string> $expanding
     *
     * @throws MakefileErrorException
     */
    public static function value(VariableExpander $expander, string $name, array $expanding): string
    {
        $binding = $expander->binding($name);
        if ($binding === null) {
            UndefinedVariable::warn($expander, $name);
            return '';
        }
        return $expander->expandBinding($binding, $expanding);
    }
}
