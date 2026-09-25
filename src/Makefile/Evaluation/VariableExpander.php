<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use LogicException;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Rule\Pattern;

use function array_slice;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

final readonly class VariableExpander
{
    public EvaluationContext $context;

    /**
     * @param list<Variable>|EvaluationContext $variables
     * @param array<string, Variable> $locals
     * @param list<string> $expanding
     */
    public function __construct(
        array|EvaluationContext $variables,
        public ?Output $output = null,
        public int $callParameters = 0,
        public ?string $source = null,
        private array $locals = [],
        public ?string $definitionSource = null,
        private array $expanding = [],
        public ?VariableScope $scope = null,
    ) {
        $this->context = $variables instanceof EvaluationContext ? $variables : new EvaluationContext($variables);
    }

    public function atSource(?string $source): self
    {
        return new self(
            $this->context,
            $this->output,
            $this->callParameters,
            $source,
            $this->locals,
            $this->definitionSource,
            $this->expanding,
            $this->scope,
        );
    }

    /**
     * @param list<string>|null $expanding
     *
     * @throws MakefileErrorException
     */
    public function expand(string $expression, ?array $expanding = null): string
    {
        $expanding ??= $this->expanding;
        $result = '';
        for ($index = 0; $index < strlen($expression); $index++) {
            if ($expression[$index] !== '$') {
                $result .= $expression[$index];
                continue;
            }
            $next = $expression[++$index] ?? '';
            if ($next === '$') {
                $result .= '$';
            } elseif ($next === '(' || $next === '{') {
                $result .= $this->reference(ExpansionSyntax::readReference($expression, $index), $expanding, $next);
            } elseif ($next !== '') {
                $result .= $this->value($next, $expanding);
            }
        }
        return $result;
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $expanding
     *
     * @throws MakefileErrorException
     */
    public function invokeFunction(
        string $name,
        array $arguments,
        array $expanding = [],
        bool $argumentsExpanded = false,
    ): ?string {
        $maximum = Functions::ARGUMENT_COUNTS[$name] ?? null;
        if ($maximum === null) {
            return null;
        }
        $arguments = array_slice($arguments, 0, $maximum);
        if (in_array($name, ['if', 'and', 'or', 'intcmp'], true)) {
            $expand = $argumentsExpanded
                ? static fn(string $argument): string => $argument
                : /** @throws MakefileErrorException */
                fn(string $argument): string => $this->expand($argument, $expanding);
            return match ($name) {
                'intcmp' => Functions::intcmp($expand, $arguments, $this->source),
                'if' => Functions::if($expand, $arguments[0] ?? null, $arguments[1] ?? null, $arguments[2] ?? ''),
                'and' => Functions::and($arguments, $expand),
                'or' => Functions::or($arguments, $expand),
            };
        }
        if ($name === 'foreach' || $name === 'let') {
            return match ($name) {
                'foreach' => Functions::foreach($this, $expanding, ...$arguments),
                'let' => Functions::let($this, $expanding, ...$arguments),
            };
        }
        if (!$argumentsExpanded) {
            if (in_array($name, ['info', 'warning', 'error'], true)) {
                $arguments[0] = ltrim($arguments[0] ?? '');
            }
            foreach ($arguments as &$argument) {
                $argument = $this->expand($argument, $expanding);
            }
            unset($argument);
        }
        $first = $arguments[0] ?? null;
        $second = $arguments[1] ?? null;
        $third = $arguments[2] ?? null;
        return match ($name) {
            'shell' => Functions::shell(
                $this->context->shell ?? throw new LogicException('Missing shell service'),
                $this->inExpansion($expanding),
                $first,
                $this->output,
            ),
            'file' => Functions::file(
                $this->context->filesystem ?? throw new LogicException('Missing filesystem service'),
                $first,
                $second,
                $this->source,
            ),
            'wildcard' => Functions::wildcard(
                $this->context->filesystem ?? throw new LogicException('Missing filesystem service'),
                $first,
            ),
            'abspath' => Functions::abspath(
                $this->context->filesystem ?? throw new LogicException('Missing filesystem service'),
                $first,
            ),
            'realpath' => Functions::realpath(
                $this->context->filesystem ?? throw new LogicException('Missing filesystem service'),
                $first,
            ),
            'call' => Functions::call($arguments, $this, $expanding),
            'eval' => Functions::eval($first ?? '', $this->context->evaluate, $this->inExpansion($expanding)),
            'info' => Functions::info($first ?? '', $this->output),
            'warning' => Functions::warning($first ?? '', $this->output, $this->source),
            'error' => Functions::error($first ?? '', $this->source),
            'value' => Functions::value($this, $first),
            'flavor' => Functions::flavor($this, $first),
            'origin' => Functions::origin($this, $first),
            'subst' => Functions::subst($first, $second, $third),
            'patsubst' => Functions::patsubst($first, $second, $third),
            'strip' => Functions::strip($first),
            'findstring' => Functions::findstring($first, $second),
            'filter' => Functions::filter($first, $second),
            'filter-out' => Functions::filterOut($first, $second),
            'sort' => Functions::sort($first),
            'word' => Functions::word($first, $second),
            'wordlist' => Functions::wordlist($first, $second, $third),
            'words' => Functions::words($first),
            'firstword' => Functions::firstword($first),
            'lastword' => Functions::lastword($first),
            'addprefix' => Functions::addprefix($first, $second),
            'addsuffix' => Functions::addsuffix($first, $second),
            'join' => Functions::join($first, $second),
            'dir' => Functions::dir($first),
            'notdir' => Functions::notdir($first),
            'basename' => Functions::basename($first),
            'suffix' => Functions::suffix($first),
            default => throw new LogicException("Unknown function: $name"),
        };
    }

    public function variable(string $name): ?Variable
    {
        return (
            $this->locals[$name]
            ?? ($this->scope === null ? $this->context->variables[$name] ?? null : $this->scope->variable($name))
        );
    }

    /**
     * @return list<Variable>
     */
    public function variables(): array
    {
        $variables = $this->context->variables;
        if ($this->scope !== null) {
            $variables = [];
            foreach ($this->scope->variables() as $variable) {
                $variables[$variable->name] = $variable;
            }
        }
        return array_values([...$variables, ...$this->locals]);
    }

    /**
     * @param list<Variable> $variables
     */
    public function withVariables(array $variables, ?int $callParameters = null): self
    {
        $locals = $this->locals;
        foreach ($variables as $variable) {
            $locals[$variable->name] = $variable;
        }
        return new self(
            $this->context,
            $this->output,
            $callParameters ?? $this->callParameters,
            $this->source,
            $locals,
            $this->definitionSource,
            $this->expanding,
            $this->scope,
        );
    }

    /**
     * @param list<string> $expanding
     */
    private function inExpansion(array $expanding): self
    {
        return new self(
            $this->context,
            $this->output,
            $this->callParameters,
            $this->source,
            $this->locals,
            $this->definitionSource,
            $expanding,
            $this->scope,
        );
    }

    /**
     * @param list<string> $expanding
     *
     * @throws MakefileErrorException
     */
    private function reference(string $reference, array $expanding, string $opening): string
    {
        $matches = [];
        if (preg_match('/^([a-z-]+)[ \t\n]+/', $reference, $matches) === 1) {
            /** @var array{non-empty-string, non-empty-string} $matches */
            $argumentCount = Functions::ARGUMENT_COUNTS[$matches[1]] ?? null;
            if ($argumentCount !== null) {
                try {
                    return (
                        $this->invokeFunction(
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
                    throw new MakefileErrorException(
                        $error->getMessage(),
                        $error->source ?? $this->definitionSource ?? $this->source,
                    );
                }
            }
        }
        $reference = $this->expand($reference, $expanding);
        $colon = strpos($reference, ':');
        if ($colon === false || !str_contains(substr($reference, $colon + 1), '=')) {
            return $this->value($reference, $expanding);
        }
        [$from, $to] = explode('=', substr($reference, $colon + 1), 2);
        $words = preg_split(
            '/\s+/',
            trim($this->value(substr($reference, 0, $colon), $expanding)),
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
    private function value(string $name, array $expanding): string
    {
        $variable = $this->variable($name);
        if ($variable === null) {
            return '';
        }
        if (!$variable->recursive) {
            return $variable->expression;
        }
        if (in_array($name, $expanding, true)) {
            if ($this->context->shellEnvironment) {
                return $this->context->inheritedEnvironment[$name] ?? '';
            }
            throw new MakefileErrorException("Recursive variable `{$name}'");
        }
        return new self(
            $this->context,
            $this->output,
            $this->callParameters,
            $this->source,
            $this->locals,
            $variable->source ?? $this->definitionSource,
            $this->expanding,
            $this->scope,
        )->expand($variable->expression, [...$expanding, $name]);
    }
}
