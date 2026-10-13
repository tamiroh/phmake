<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Parser\Internal\LineText;

use function ltrim;
use function preg_match;
use function str_contains;
use function strcspn;
use function strlen;
use function strrpos;
use function strspn;
use function substr;
use function substr_count;
use function trim;

/**
 * Parses one source line into syntax nodes without expanding variables.
 * Shared by whole-file parsing and evaluation of deferred logical lines.
 */
final readonly class LineParser
{
    /**
     * @return array{'define'|'endef', string}|null
     */
    public static function definitionBoundary(string $line): ?array
    {
        $text = trim(LineText::removeComment($line));
        if (preg_match('/^define(?:[ \t]+(?![:+?!=])\S|$)/', $text) === 1) {
            return ['define', ''];
        }
        $matches = [];
        return preg_match('/^endef(?:\s+(.*))?$/', $text, $matches) === 1 ? ['endef', $matches[1] ?? ''] : null;
    }

    /**
     * Whether a line is a variable assignment, optionally following a target colon.
     */
    public static function isAssignment(string $text, bool $scoped = false): bool
    {
        if ($scoped) {
            [$text] = self::modifiers($text, scoped: true);
        }
        return self::assignment($text) !== null;
    }

    public static function parse(Ast\SourceSpan $span, string $raw, string $line): Ast\Node
    {
        $text = LineText::removeComment($line);
        if (trim($text, " \t\n\r\0\x0B\f") === '') {
            return new Ast\TriviaNode($span, $raw);
        }
        $matches = [];
        if (
            preg_match('/^\s*(ifdef|ifndef|ifeq|ifneq|else|endif)(?:[ \t]+|$)(.*)$/s', $text, $matches) === 1
            && preg_match('/^\s*(?::=|\+=|\?=|=)/', $matches[2]) !== 1
        ) {
            return new Ast\ConditionalDirectiveNode($span, $raw, $matches[1], $matches[2]);
        }
        $skipDefine = preg_match('/^\s*(?:(?:override|export|unexport)\s+)*define\s+(?![:+?!=])/', $text) === 1;
        [$text, $modifiers] = self::modifiers($text);
        $assignment = self::assignment($text);
        if (
            $assignment === null
            && preg_match('/^define(?:[ \t]+(.*)|$)/s', $text, $matches) === 1
            && preg_match('/^(?::::=|::=|:=|!=|\+=|\?=|=)/', ltrim($matches[1] ?? '')) !== 1
        ) {
            [$name, $operator, $expression] = self::assignment($matches[1] ?? '', allowWhitespace: true) ?? [
                trim($matches[1] ?? ''),
                '=',
                '',
            ];
            return new Ast\DefineHeaderNode(
                $span,
                $raw,
                new Ast\AssignmentNode($span, $raw, $name, $operator, $expression, $modifiers),
                $skipDefine,
            );
        }
        if (
            $assignment === null
            && preg_match('/^undefine(?:[ \t]+(.*)|$)/s', $text, $matches) === 1
            && preg_match('/^(?::::=|::=|:=|!=|\+=|\?=|=)/', ltrim($matches[1] ?? '')) !== 1
        ) {
            return new Ast\DirectiveNode($span, $raw, 'undefine', trim($matches[1] ?? ''), $modifiers);
        }
        if ($assignment === null && preg_match('/^endef(?:\s|$)/', $text) === 1) {
            return new Ast\DirectiveNode($span, $raw, 'endef', '', $modifiers);
        }
        if ($assignment === null) {
            $export = null;
            foreach ($modifiers as $modifier) {
                if ($modifier === 'export' || $modifier === 'unexport') {
                    $export = $modifier;
                }
            }
            if ($export !== null) {
                return new Ast\DirectiveNode($span, $raw, $export, $text, $modifiers);
            }
        }
        $exportAll = preg_match('/^\\.EXPORT_ALL_VARIABLES\\s*:/', $text) === 1;
        if ($assignment !== null) {
            return new Ast\AssignmentNode(
                $span,
                $raw,
                $assignment[0],
                $assignment[1],
                $assignment[2],
                $modifiers,
                $exportAll,
            );
        }
        if (preg_match('/^\s*(-?include|sinclude)(?:\s+(.*))?$/', $text, $matches) === 1) {
            return new Ast\IncludeNode($span, $raw, $matches[1], $matches[2] ?? '');
        }
        if (preg_match('/^(-?load|vpath)(?:[ \t]+(.*)|$)/s', $text, $matches) === 1) {
            return new Ast\DirectiveNode($span, $raw, $matches[1], $matches[2] ?? '', $modifiers);
        }
        $colon = self::delimiter($text, ':');
        if ($colon !== null) {
            $scopedText = substr($text, $colon + 1);
            [$value, $scopedModifiers] = self::modifiers($scopedText, scoped: true);
            $scoped = self::assignment($value);
            if ($scoped !== null) {
                // Offsets refer to the original spelling, not the uncommented text.
                $rawColon = self::delimiter($line, ':') ?? $colon;
                $assignmentSpan = self::tailSpan($span, $raw, $rawColon + 1);
                return new Ast\TargetAssignmentNode(
                    $span,
                    $raw,
                    substr($text, 0, $colon),
                    new Ast\AssignmentNode(
                        $assignmentSpan,
                        substr($raw, $rawColon + 1),
                        $scoped[0],
                        $scoped[1],
                        $scoped[2],
                        $scopedModifiers,
                    ),
                    $exportAll,
                );
            }
        }
        [$header, $recipe] = LineText::splitRecipe($line);
        $inline = null;
        if ($recipe !== null) {
            $offset = strlen($line) - strlen($recipe);
            $inline = new Ast\RecipeNode(self::tailSpan($span, $raw, $offset), substr($raw, $offset), $recipe);
        }
        return self::delimiter($header, ':') === null
            ? new Ast\ExpressionNode($span, $raw, $header, $inline)
            : new Ast\RuleNode($span, $raw, $header, $inline, $exportAll);
    }

    /**
     * @return array{string, string, string}|null
     */
    private static function assignment(string $text, bool $allowWhitespace = false): ?array
    {
        if (!str_contains($text, '=')) {
            return null;
        }
        $text = ltrim($text);
        $depth = 0;
        $length = strlen($text);
        // Skip in C to the next character that can matter; names and values rarely contain one.
        $stops = "(){} \t:!+?=#";
        for ($index = strcspn($text, $stops); $index < $length; $index += 1 + strcspn($text, $stops, $index + 1)) {
            if (
                ($text[$index] === '(' || $text[$index] === '{')
                && ($depth > 0 || $index > 0 && $text[$index - 1] === '$')
            ) {
                $depth++;
            } elseif (($text[$index] === ')' || $text[$index] === '}') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0) {
                if (!$allowWhitespace && str_contains(" \t", $text[$index])) {
                    $index += strspn($text, " \t", $index);
                    if (preg_match('/^(:::=|::=|:=|!=|\+=|\?=|=)/', substr($text, $index)) !== 1) {
                        return null;
                    }
                }
                $matches = [];
                if (
                    str_contains(':!+?=', $text[$index])
                    && preg_match('/^(:::=|::=|:=|!=|\+=|\?=|=)/', substr($text, $index), $matches) === 1
                ) {
                    /** @var array{non-falsy-string, ':::='|'::='|':='|'!='|'+='|'?='|'='} $matches */
                    return [
                        trim(substr($text, 0, $index)),
                        $matches[1],
                        ltrim(substr($text, $index + strlen($matches[1]))),
                    ];
                }
                if ($text[$index] === ':' || $text[$index] === '#') {
                    return null;
                }
            }
        }
        return null;
    }

    private static function delimiter(string $text, string $delimiter): ?int
    {
        $depth = 0;
        for ($index = 0; $index < strlen($text); $index++) {
            $character = $text[$index];
            if ($character === '\\') {
                $index++;
                continue;
            }
            if (($character === '(' || $character === '{') && ($depth > 0 || $index > 0 && $text[$index - 1] === '$')) {
                $depth++;
            } elseif (($character === ')' || $character === '}') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && $character === $delimiter) {
                return $index;
            }
        }
        return null;
    }

    /**
     * @return array{string, list<string>}
     */
    private static function modifiers(string $text, bool $scoped = false): array
    {
        $text = ltrim($text);
        $modifiers = [];
        $matches = [];
        $pattern = $scoped
            ? '/^(override|private|export|unexport)\s+(.*)$/s'
            : '/^(override|private|export|unexport)(?:[ \t]+|$)(.*)$/s';
        while (preg_match($pattern, $text, $matches) === 1) {
            if (
                $scoped
                    ? self::assignment($text) !== null
                    : preg_match('/^(?::::=|::=|:=|!=|\+=|\?=|=)/', ltrim($matches[2])) === 1
            ) {
                break;
            }
            $modifiers[] = $matches[1];
            $text = ltrim($matches[2]);
        }
        return [$text, $modifiers];
    }

    private static function tailSpan(Ast\SourceSpan $span, string $raw, int $offset): Ast\SourceSpan
    {
        $prefix = substr($raw, 0, $offset);
        $newline = strrpos($prefix, "\n");
        return new Ast\SourceSpan(
            $span->file,
            $span->startOffset + $offset,
            $span->endOffset,
            $span->startLine + substr_count($prefix, "\n"),
            $newline === false ? $span->startColumn + $offset : strlen($prefix) - $newline,
            $span->endLine,
            $span->endColumn,
        );
    }
}
