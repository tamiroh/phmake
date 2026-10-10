<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Override;
use Tamiroh\Phmake\Engine\Evaluation\Configuration;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;

final class FakeConfiguration implements Configuration
{
    /**
     * @param list<string> $includeDirectories
     */
    public function __construct(
        #[Override]
        public array $includeDirectories = [],
        #[Override]
        public bool $noBuiltinRules = false,
    ) {}

    /**
     * @throws void
     */
    #[Override]
    public function finishReading(array &$variables, VariableExpander $expander): void {}

    /**
     * @throws void
     */
    #[Override]
    public function updateMakeflags(
        array &$variables,
        ?VariableExpander $expander = null,
        string $origin = 'file',
    ): void {}
}
