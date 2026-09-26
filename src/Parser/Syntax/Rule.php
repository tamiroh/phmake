<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Syntax;

use Tamiroh\Phmake\Makefile\Execution\Recipe\Command;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;

use function trim;

final class Rule
{
    /** @var list<Command> */
    public private(set) array $commands = [];

    public private(set) bool $hasRecipe = false;

    /**
     * @param list<string> $targetNames
     */
    public function __construct(
        public readonly array $targetNames,
        public readonly Prerequisites $prerequisites,
        public readonly int $lineNumber,
        public readonly bool $doubleColon = false,
        public readonly bool $grouped = false,
        public readonly ?string $targetPattern = null,
        public readonly ?string $source = null,
    ) {}

    public function addRecipe(string $recipe, ?string $source = null): void
    {
        $this->hasRecipe = true;
        if (trim($recipe) !== '') {
            $this->commands[] = new Command($recipe, $source);
        }
    }
}
