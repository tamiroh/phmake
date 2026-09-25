<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final readonly class Makefile
{
    /** @var array<string, Target> */
    public array $targetsByName;

    /**
     * @param list<Target> $targets
     * @param list<Variable> $variables
     * @param list<PatternRule> $patterns
     */
    public function __construct(
        public array $targets = [],
        public array $variables = [],
        public ?string $defaultGoal = null,
        public array $patterns = [],
        public Exports $exports = new Exports(),
        public ?EvaluationContext $context = null,
        public TargetVariables $scopes = new TargetVariables(),
        public SearchPaths $paths = new SearchPaths(),
    ) {
        $indexed = [];
        foreach ($targets as $target) {
            $indexed[$target->name] = $target;
        }
        $this->targetsByName = $indexed;
    }

    /** @param list<string> $targets
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(array $targets, Shell $shell, Filesystem $filesystem, Output $output): void
    {
        new Build($this, $shell, $filesystem, $output)->run($targets);
    }
}
