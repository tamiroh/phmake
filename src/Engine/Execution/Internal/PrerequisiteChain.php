<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Internal;

use Tamiroh\Phmake\Engine\Variable\VariableScope;

/**
 * The targets in one prerequisite chain and the target-specific variables inherited along it.
 * Each concurrent branch keeps its own chain; shared evaluation state is not cloned.
 *
 * @internal
 */
final class PrerequisiteChain
{
    /** @var array<string, true> */
    public array $ancestors = [];

    public function __construct(
        public VariableScope $scope,
    ) {}
}
