<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Variable;

use Tamiroh\Phmake\Engine\Rule\Pattern;

/**
 * A pattern declaration is installed into the matched target's variable set later.
 */
final readonly class PatternAssignment
{
    public function __construct(
        public Pattern $pattern,
        public Assignment $assignment,
        public string $origin,
        public bool $private,
        public ?bool $export,
        public ?string $source,
        public ?string $evaluated = null,
    ) {}
}
