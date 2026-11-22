<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Makefile;

use Tamiroh\Phmake\Engine\Execution\Build;
use Tamiroh\Phmake\Engine\Expansion\EvaluationContext;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\Rule\BuildRule;
use Tamiroh\Phmake\Engine\Variable\Variable;

use function ctype_digit;
use function implode;
use function str_contains;
use function str_replace;
use function strrpos;
use function substr;
use function trim;

final class DatabasePrinter
{
    public static function format(Makefile $makefile, EvaluationContext $context, Build $build): string
    {
        $text = "\n# Variables\n\n";
        foreach ($context->variables as $variable) {
            $text .= self::variable($variable);
        }
        $text .= "\n# Implicit Rules\n\n";
        foreach ($makefile->patterns as $pattern) {
            $text .= self::rule(implode(' ', $pattern->names), $pattern->rule);
        }
        $text .= "\n# Files\n\n";
        foreach ($makefile->targetVariables->mentioned() as $name) {
            foreach ($makefile->targetVariables->definitionsFor($name) ?? [] as $variable) {
                $text .= self::variable($variable, $name . ': ');
            }
        }
        foreach ($build->databaseFiles as [$name, $rule, $searched, $result]) {
            $search = $searched ? 'has been done' : 'has not been done';
            $status = $result === null ? "#  File has not been updated.\n" : "#  File has been updated.\n";
            $status .= match (true) {
                $result === null => '',
                $result->failure !== null => "#  Failed to be updated.\n",
                $result->needsUpdate => "#  Needs to be updated (-q is set).\n",
                default => "#  Successfully updated.\n",
            };
            $details = "#  Implicit rule search {$search}.\n";
            if ($rule->implicit || $rule->stem !== '') {
                $details .= "#  Implicit/static pattern stem: '{$rule->stem}'\n";
            }
            $text .= self::rule($name, $rule, $details . $status);
        }
        return $text;
    }

    private static function rule(string $name, BuildRule $rule, string $details = ''): string
    {
        $deps = $rule->prerequisites;
        $text = $name . ($rule->doubleColon ? '::' : ':');
        foreach ($deps->normal as $dependency) {
            $text .= ' ' . $dependency;
        }
        if ($deps->orderOnly !== []) {
            $text .= ' | ' . implode(' ', $deps->orderOnly);
        }
        $text .= "\n" . $details;
        if ($rule->recipe !== null) {
            $text .= '#  recipe to execute' . self::source($rule->recipe->source) . ":\n";
            foreach ($rule->recipe->commands as $command) {
                $text .= "\t" . $command->expression . "\n";
            }
        }
        return $text . "\n";
    }

    private static function source(?string $source): string
    {
        $separator = strrpos($source ?? '', needle: ':');
        if ($source === null || $separator === false) {
            return '';
        }
        $line = substr($source, $separator + 1);
        return ctype_digit($line)
            ? " (from '" . substr($source, offset: 0, length: $separator) . "', line {$line})"
            : '';
    }

    private static function variable(Variable $variable, string $prefix = ''): string
    {
        $origin = match ($variable->origin) {
            'file' => 'makefile',
            'override' => "'override' directive",
            'environment override' => 'environment under -e',
            default => $variable->origin,
        };
        $header = '# ' . $origin . ($variable->private ? ' private' : '') . self::source($variable->source) . "\n";
        $value = $variable->expression;
        if ($variable->recursive && str_contains($value, "\n")) {
            return $header . $prefix . "define {$variable->name}\n{$value}\nendef\n";
        }
        $operator = match (true) {
            !$variable->recursive => ':=',
            $variable->append => '+=',
            default => '=',
        };
        if (!$variable->recursive) {
            $value = str_replace('$', replace: '$$', subject: $value);
        }
        if ($value !== '' && trim($value, characters: " \t") === '') {
            $value = '$(subst ,,' . $value . ')';
        }
        return $header . $prefix . "{$variable->name} {$operator} {$value}\n";
    }
}
