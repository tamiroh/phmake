<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\Command;

final class Rule
{
    /** @var list<Command> */
    public array $commands = [];

    public bool $hasRecipe = false;

    /**
     * @param list<string> $targetNames
     * @param list<string> $dependencyNames
     */
    public function __construct(
        public readonly array $targetNames,
        public readonly array $dependencyNames,
        public readonly int $lineNumber,
    ) {}

    public function addRecipe(string $recipe): void
    {
        $this->hasRecipe = true;
        if (trim($recipe) !== '') {
            $this->commands[] = new Command($recipe);
        }
    }
}
