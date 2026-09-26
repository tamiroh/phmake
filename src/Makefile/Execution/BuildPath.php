<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\Evaluation\VariableScope;

/**
 * A traversal's variable scope and ancestors, independent of concurrent branches.
 *
 * @internal
 */
final class BuildPath
{
    /** @var array<string, true> */
    public array $visiting = [];

    public function __construct(
        public VariableScope $scope,
    ) {}
}
