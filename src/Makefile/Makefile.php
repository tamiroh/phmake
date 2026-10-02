<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Tamiroh\Phmake\Makefile\Evaluation\Environment\Exports;
use Tamiroh\Phmake\Makefile\Evaluation\TargetVariables;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Rule\PatternRule;
use Tamiroh\Phmake\Makefile\Rule\Target;
use Tamiroh\Phmake\Makefile\Search\SearchPaths;

/**
 * Stored definitions, with global variables captured at the end of reading.
 * Live evaluation state is supplied separately to a build.
 */
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
        public TargetVariables $scopes = new TargetVariables(),
        public SearchPaths $paths = new SearchPaths(),
    ) {
        $indexed = [];
        foreach ($targets as $target) {
            $indexed[$target->name] = $target;
        }
        $this->targetsByName = $indexed;
    }
}
