<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Rule;

use function ltrim;
use function str_contains;
use function strspn;
use function substr;

final readonly class Command
{
    public function __construct(
        public string $expression,
        public ?string $source = null,
    ) {}

    /**
     * @pure
     */
    public function isRecursive(): bool
    {
        return (
            str_contains($this->expression, '$(MAKE)')
            || str_contains($this->expression, '${MAKE}')
            || str_contains($this->prefix(), '+')
        );
    }

    /**
     * @pure
     */
    public function prefix(): string
    {
        return substr(ltrim($this->expression), 0, strspn(ltrim($this->expression), "@-+ \t"));
    }
}
