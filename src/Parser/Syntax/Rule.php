<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser\Syntax;

use Tamiroh\Phmake\Makefile\Rule\Command;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;
use Tamiroh\Phmake\Makefile\Rule\Recipe;
use Tamiroh\Phmake\Makefile\Rule\RuleDefinition;

use function trim;

final class Rule
{
    /** @var list<Command> */
    private array $commands = [];

    private bool $hasRecipe = false;

    /**
     * @param list<string> $targetNames
     */
    public function __construct(
        public readonly array $targetNames,
        private readonly Prerequisites $prerequisites,
        private readonly bool $doubleColon = false,
        private readonly bool $grouped = false,
        private readonly ?string $targetPattern = null,
        private readonly ?string $source = null,
    ) {}

    public function addRecipe(string $recipe, ?string $source = null): void
    {
        $this->hasRecipe = true;
        if (trim($recipe) !== '') {
            $this->commands[] = new Command($recipe, $source);
        }
    }

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            $this->targetNames,
            $this->prerequisites,
            $this->hasRecipe ? new Recipe($this->commands, $this->commands[0]->source ?? $this->source) : null,
            $this->doubleColon,
            $this->grouped,
            $this->targetPattern,
            $this->source,
        );
    }
}
