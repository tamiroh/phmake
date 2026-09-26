<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation\Module;

/**
 * @internal
 */
final readonly class ModuleFunction
{
    public function __construct(
        public LoadedModule $module,
        public int $minimum,
        public int $maximum,
        public bool $expand,
    ) {}
}
