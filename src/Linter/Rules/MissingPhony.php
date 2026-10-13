<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Linter\Rules;

use Override;
use Tamiroh\Phmake\Linter\Diagnostic;
use Tamiroh\Phmake\Linter\Rule;
use Tamiroh\Phmake\Parser\Ast\MakefileNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\RuleNode;
use Tamiroh\Phmake\Parser\Ast\SourceSpan;
use Tamiroh\Phmake\Parser\Ast\Walker;

use function in_array;
use function ltrim;
use function preg_match;
use function preg_quote;
use function preg_split;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Reports targets that look like commands rather than files but are not declared .PHONY.
 * A target with a recipe is suspect unless a recipe line refers to it, through $@ or by
 * name. A target without one is suspect when it depends on a phony target.
 */
final readonly class MissingPhony implements Rule
{
    /**
     * Names that are commands by convention, even when their recipe repeats the name
     * as a tool argument (for example "lint: mago lint").
     */
    private const array CONVENTIONAL = [
        'all',
        'check',
        'clean',
        'distclean',
        'fmt',
        'format',
        'help',
        'install',
        'lint',
        'run',
        'test',
        'uninstall',
    ];

    /**
     * Splits an explicit rule header into target and prerequisite words. Each variable
     * reference is reduced to "$" so that words containing one stay recognizable.
     *
     * @return array{list<string>, list<string>}|null
     */
    private static function header(string $header): ?array
    {
        $header = str_replace(["\\\r\n", "\\\n"], ' ', $header);
        $text = '';
        $depth = 0;
        for ($index = 0; $index < strlen($header); $index++) {
            $character = $header[$index];
            if (
                ($character === '(' || $character === '{')
                && ($depth > 0 || $index > 0 && $header[$index - 1] === '$')
            ) {
                $depth++;
            } elseif (($character === ')' || $character === '}') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0) {
                $text .= $character;
            }
        }
        $colon = strpos($text, ':');
        if ($colon === false) {
            return null;
        }
        $targets = substr($text, 0, $colon);
        $rest = ltrim(substr($text, $colon + 1), ':');
        // Variable targets are unknown, and a second colon makes a static pattern rule.
        if (str_contains($targets, '$') || str_contains($rest, ':')) {
            return null;
        }
        return [self::words($targets, '&'), self::words($rest, '|')];
    }

    /**
     * @param list<string> $lines
     */
    private static function refersToTarget(string $name, array $lines): bool
    {
        $mention = '/(?<![\w.\/-])' . preg_quote($name, '/') . '(?![\w.\/-])/';
        foreach ($lines as $line) {
            // An even run of dollars before "$@" escapes it for the shell.
            if (preg_match('/(?<!\$)(?:\$\$)*\$(?:@|[({]@)/', $line) === 1) {
                return true;
            }
            if (!in_array($name, self::CONVENTIONAL, true) && preg_match($mention, $line) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text, string $separator): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return [];
        }
        $result = [];
        foreach ($words as $word) {
            if ($word !== $separator) {
                $result[] = $word;
            }
        }
        return $result;
    }

    #[Override]
    public function inspect(Node $node): array
    {
        if (!$node instanceof MakefileNode) {
            return [];
        }
        /** @var array<string, true> $phony */
        $phony = [];
        $dynamic = false;
        // Numeric names become integer keys, so names are also kept as strings.
        /** @var list<string> $names */
        $names = [];
        /** @var array<string, SourceSpan> $spans */
        $spans = [];
        /** @var array<string, list<string>> $prerequisites */
        $prerequisites = [];
        /** @var array<string, list<string>> $recipes */
        $recipes = [];
        new Walker()->walk($node, static function (Node $child) use (
            &$phony,
            &$dynamic,
            &$names,
            &$spans,
            &$prerequisites,
            &$recipes,
        ): void {
            $header = $child instanceof RuleNode ? self::header($child->header) : null;
            if (!$child instanceof RuleNode || $header === null) {
                return;
            }
            if (in_array('.PHONY', $header[0], true)) {
                foreach ($header[1] as $name) {
                    if (str_contains($name, '$')) {
                        $dynamic = true;
                    } else {
                        $phony[$name] = true;
                    }
                }
                return;
            }
            foreach ($header[0] as $name) {
                if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_-]*\z/', $name) !== 1) {
                    continue;
                }
                if (!isset($spans[$name])) {
                    $names[] = $name;
                    $spans[$name] = ($child->children[0] ?? $child)->span;
                }
                $prerequisites[$name] = [...($prerequisites[$name] ?? []), ...$header[1]];
                $recipes[$name] ??= [];
                foreach ($child->recipes as $recipe) {
                    if (trim($recipe->expression) !== '') {
                        $recipes[$name][] = $recipe->expression;
                    }
                }
            }
        });
        // A computed .PHONY list cannot be compared with target names lexically.
        if ($dynamic) {
            return [];
        }
        $suspects = [];
        foreach ($names as $name) {
            $lines = $recipes[$name] ?? [];
            if ($lines !== [] && !isset($phony[$name]) && !self::refersToTarget($name, $lines)) {
                $suspects[$name] = true;
            }
        }
        do {
            $changed = false;
            foreach ($names as $name) {
                if (($recipes[$name] ?? []) !== [] || isset($phony[$name]) || isset($suspects[$name])) {
                    continue;
                }
                foreach ($prerequisites[$name] ?? [] as $prerequisite) {
                    if (isset($phony[$prerequisite]) || isset($suspects[$prerequisite])) {
                        $suspects[$name] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
        $diagnostics = [];
        foreach ($names as $name) {
            if (isset($suspects[$name], $spans[$name])) {
                $diagnostics[] = new Diagnostic(
                    'missing-phony',
                    "Target '{$name}' looks phony but is not declared in .PHONY",
                    $spans[$name],
                );
            }
        }
        return $diagnostics;
    }
}
