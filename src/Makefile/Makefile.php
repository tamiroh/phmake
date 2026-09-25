<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Tamiroh\Phmake\Makefile\Evaluation\EvaluationContext;
use Tamiroh\Phmake\Makefile\Evaluation\Exports;
use Tamiroh\Phmake\Makefile\Evaluation\TargetVariables;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Execution\Build;
use Tamiroh\Phmake\Makefile\Execution\CommandFailedException;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\Rule\PatternRule;
use Tamiroh\Phmake\Makefile\Rule\Target;
use Tamiroh\Phmake\Makefile\Search\SearchPaths;

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

    /**
     * @param list<string> $targets
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(array $targets, Shell $shell, Filesystem $filesystem, Output $output): void
    {
        new Build($this, $shell, $filesystem, $output)->run($targets);
    }
}
