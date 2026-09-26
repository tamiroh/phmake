<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use Closure;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\Rule\ArchiveMember;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;

use function array_unique;
use function basename;
use function dirname;
use function implode;
use function in_array;

final class AutomaticVariables
{
    /**
     * @param list<string> $changed
     * @param Closure(string): ?int|null $timestamp
     *
     * @return list<Variable>
     */
    public static function forRule(
        string $name,
        BuildRule $rule,
        ?int $modifiedAt,
        array $changed,
        Filesystem $filesystem,
        ?Closure $timestamp = null,
    ): array {
        $newer = [];
        foreach (array_unique($rule->prerequisites->normal) as $dependency) {
            $time = $timestamp === null ? $filesystem->lastModified($dependency) : $timestamp($dependency);
            if (
                $modifiedAt === null
                || $time === null
                || $time > $modifiedAt
                || in_array($dependency, $changed, true)
            ) {
                $newer[] = $dependency;
            }
        }
        $member = ArchiveMember::parse($name);
        $values = [
            '@' => [$member->archive ?? $name],
            '<' => [$rule->firstPrerequisite ?? $rule->prerequisites->normal[0] ?? ''],
            '^' => array_unique($rule->prerequisites->normal),
            '+' => $rule->prerequisites->normal,
            '|' => $rule->prerequisites->orderOnly,
            '?' => $newer,
            '*' => [$rule->stem],
            '%' => $member === null ? [] : [$member->member],
        ];
        $variables = [];
        foreach ($values as $key => $words) {
            if (in_array($key, ['^', '+', '|', '?'], true)) {
                $words = array_map(ArchiveMember::prerequisite(...), $words);
            }
            $variables[] = new Variable($key, implode(' ', $words), false, 'automatic');
            $directories = [];
            $files = [];
            foreach ($words as $word) {
                if ($word !== '') {
                    $directories[] = dirname($word);
                    $files[] = basename($word);
                }
            }
            $variables[] = new Variable($key . 'D', implode(' ', $directories), false, 'automatic');
            $variables[] = new Variable($key . 'F', implode(' ', $files), false, 'automatic');
        }
        return $variables;
    }
}
