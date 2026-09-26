<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Rule;

use function array_diff;
use function array_unique;
use function array_values;

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
     */
    public function __construct(
        array $normal = [],
        array $orderOnly = [],
        public array $expressions = [],
        ?array $sequence = null,
        public array $literal = [],
    ) {
        $this->sequence = $sequence ?? [...$normal, ...$orderOnly];
        $this->normal = array_values(array_diff($normal, ['.WAIT']));
        $orderOnly = array_values(array_diff($orderOnly, ['.WAIT']));
        $this->orderOnly = array_values(array_unique(array_diff($orderOnly, $normal)));
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->normal, ...$other->normal],
            [...$this->orderOnly, ...$other->orderOnly],
            [...$this->expressions, ...$other->expressions],
            [...$this->sequence, ...$other->sequence],
            [...$this->literal, ...$other->literal],
        );
    }
}
