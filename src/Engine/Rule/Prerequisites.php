<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Rule;

use function array_diff;
use function array_unique;
use function array_values;
use function str_contains;

final readonly class Prerequisites
{
    /** @var list<string> */
    public array $normal;

    /** @var list<string> */
    public array $orderOnly;

    /** @var list<string> */
    public array $sequence;

    /**
     * @param list<string> $normal
     * @param list<string> $orderOnly
     * @param list<PrerequisiteExpression> $expressions
     * @param list<string>|null $sequence
     * @param list<string> $literal names produced without stem substitution during implicit expansion
     * @param list<string> $extra normal dependencies excluded from automatic variables
     * @param list<string> $extraOrderOnly order-only dependencies excluded from automatic variables
     */
    public function __construct(
        array $normal = [],
        array $orderOnly = [],
        public array $expressions = [],
        ?array $sequence = null,
        public array $literal = [],
        public array $extra = [],
        public array $extraOrderOnly = [],
    ) {
        $this->sequence = $sequence ?? [...$normal, ...$orderOnly, ...$extra, ...$extraOrderOnly];
        $this->normal = array_values(array_diff($normal, ['.WAIT']));
        $orderOnly = array_values(array_diff($orderOnly, ['.WAIT']));
        $this->orderOnly = array_diff($orderOnly, $normal) |> array_unique(...) |> array_values(...);
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->normal, ...$other->normal],
            [...$this->orderOnly, ...$other->orderOnly],
            [...$this->expressions, ...$other->expressions],
            [...$this->sequence, ...$other->sequence],
            [...$this->literal, ...$other->literal],
            [...$this->extra, ...$other->extra],
            [...$this->extraOrderOnly, ...$other->extraOrderOnly],
        );
    }

    /**
     * Snapshot .NOTPARALLEL on the edges present now. A deferred expression is
     * replaced during secondary expansion, so its future edges get no waits.
     */
    public function withBarriers(bool $leading = false): self
    {
        $sequence = [];
        $seen = $leading;
        foreach ($this->sequence as $name) {
            if ($seen && $name !== '.WAIT') {
                $sequence[] = '.WAIT';
            }
            $sequence[] = $name;
            $seen = true;
        }
        $expressions = [];
        $seen = $leading;
        foreach ($this->expressions as $expression) {
            if ($expression->secondary && str_contains($expression->text, '$')) {
                $expressions[] = $expression;
                $seen = true;
                continue;
            }
            $initial = $expression->initial?->withBarriers($seen);
            $expressions[] = new PrerequisiteExpression(
                $expression->text,
                $expression->stem,
                $expression->hasRecipe,
                $expression->source,
                $expression->secondary,
                $initial,
            );
            $seen = $seen || $initial !== null && $initial->sequence !== [];
        }
        return new self(
            $this->normal,
            $this->orderOnly,
            $expressions,
            $sequence,
            $this->literal,
            $this->extra,
            $this->extraOrderOnly,
        );
    }
}
