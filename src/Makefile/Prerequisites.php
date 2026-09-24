<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use function array_diff;
use function array_unique;
use function array_values;

final readonly class Prerequisites
{
    /** @var list<string> */
    public array $orderOnly;

    /**
     * @param list<string> $normal
     * @param list<string> $orderOnly
     * @param list<PrerequisiteExpression> $expressions
     */
    public function __construct(
        public array $normal = [],
        array $orderOnly = [],
        public array $expressions = [],
    ) {
        $this->orderOnly = array_values(array_unique(array_diff($orderOnly, $normal)));
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->normal, ...$other->normal],
            [...$this->orderOnly, ...$other->orderOnly],
            [...$this->expressions, ...$other->expressions],
        );
    }
}
