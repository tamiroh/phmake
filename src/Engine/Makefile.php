<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine;

use Tamiroh\Phmake\Engine\Rule\PatternRule;
use Tamiroh\Phmake\Engine\Rule\Target;
use Tamiroh\Phmake\Engine\Search\SearchPaths;
use Tamiroh\Phmake\Engine\Variable\Environment\Exports;
use Tamiroh\Phmake\Engine\Variable\TargetVariables;
use Tamiroh\Phmake\Engine\Variable\Variable;

/**
 * Rules, variables, exports, and directory search definitions read from makefiles.
 * Global variables are captured at the end of reading; deferred expressions are
 * expanded in the live context passed to Execution/Build.
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
        public TargetVariables $targetVariables = new TargetVariables(),
        public SearchPaths $searchPaths = new SearchPaths(),
    ) {
        $indexed = [];
        foreach ($targets as $target) {
            $indexed[$target->name] = $target;
        }
        $this->targetsByName = $indexed;
    }
}
