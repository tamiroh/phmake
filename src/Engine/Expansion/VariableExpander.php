<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Expansion;

use LogicException;
use Tamiroh\Phmake\Engine\Expansion\Internal\ExpansionSyntax;
use Tamiroh\Phmake\Engine\Expansion\Internal\VariableReference;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Variable\Variable;
use Tamiroh\Phmake\Engine\Variable\VariableBinding;
use Tamiroh\Phmake\Engine\Variable\VariableScope;

use function array_keys;
use function array_slice;
use function array_values;
use function implode;
use function in_array;
use function ltrim;
use function strlen;
use function strpos;
use function substr;

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
        public bool $secondary = false,
    ) {
        $this->context = $variables instanceof EvaluationContext ? $variables : new EvaluationContext($variables);
    }

    public function atSource(?string $source, ?bool $secondary = null): self
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
            $secondary ?? $this->secondary,
        );
    }

    public function binding(string $name, bool $environment = false): ?VariableBinding
    {
        if (!isset($this->locals[$name]) && $this->scope !== null && $name !== '.VARIABLES') {
            return $this->scope->binding($name, $environment);
        }
        $variable = $this->variable($name);
        return $variable === null ? null : new VariableBinding($variable);
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
        $length = strlen($expression);
        $index = 0;
        // Copy the text between references at once.
        while ($index < $length && ($dollar = strpos($expression, '$', $index)) !== false) {
            $result .= substr($expression, $index, $dollar - $index);
            $index = $dollar + 1;
            $next = $expression[$index] ?? '';
            if ($next === '$') {
                $result .= '$';
            } elseif ($next === '(' || $next === '{') {
                $result .= VariableReference::expand(
                    $this,
                    ExpansionSyntax::readReference($expression, $index, $this->definitionSource ?? $this->source),
                    $expanding,
                    $next,
                );
            } elseif ($next !== '') {
                $result .= VariableReference::value($this, $next, $expanding);
            }
            $index++;
        }
        return $result . substr($expression, $index);
    }

    /**
     * @param list<string>|null $expanding
     *
     * @throws MakefileErrorException
     */
    public function expandBinding(VariableBinding $binding, ?array $expanding = null): string
    {
        $expanding ??= $this->expanding;
        $name = $binding->definition->name;
        if ($binding->definition->recursive && in_array($name, $expanding, true)) {
            if ($this->context->environment->expandingShell) {
                return $this->context->environment->inherited[$name] ?? '';
            }
            throw new MakefileErrorException("Recursive variable `{$name}'");
        }
        return $binding->expand(
            /** @throws MakefileErrorException */
            fn(Variable $variable): string => new self(
                $this->context,
                $this->output,
                $this->callParameters,
                $this->source,
                $this->locals,
                $variable->source ?? $this->definitionSource,
                $this->expanding,
                $this->scope,
                $this->secondary,
            )->expand($variable->expression, [...$expanding, $name]),
        );
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
            return $this->context->loadedObjects->invoke($name, $arguments, $this, $expanding, $argumentsExpanded);
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
            'eval' => Functions::eval($first ?? '', $this->context->reading->evaluate, $this->inExpansion($expanding)),
            'guile' => Functions::guile($first ?? '', $this->inExpansion($expanding)),
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
            default => throw new LogicException("Unknown function: {$name}"),
        };
    }

    public function variable(string $name): ?Variable
    {
        $variable =
            $this->locals[$name]
            ?? ($this->scope === null ? $this->context->variables[$name] ?? null : $this->scope->variable($name));
        if ($name === '.VARIABLES' && $variable?->origin === 'default') {
            return new Variable($name, implode(' ', array_keys($this->context->variables)), false, 'default');
        }
        return $variable;
    }

    /**
     * @return list<Variable>
     */
    public function variables(bool $environment = false): array
    {
        $variables = $this->context->variables;
        if ($this->scope !== null) {
            $variables = [];
            foreach ($this->scope->variables($environment) as $variable) {
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
            $this->secondary,
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
            $this->secondary,
        );
    }
}
