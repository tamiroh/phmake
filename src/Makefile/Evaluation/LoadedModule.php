<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use Tamiroh\Phmake\Makefile\IO\ModuleHost;

final class LoadedModule
{
    public ?ModuleHost $host = null;

    public bool $keep = false;

    public bool $reload = false;

    public function __construct(
        public readonly string $path,
        public readonly string $setup,
        public readonly ?string $source,
        public readonly bool $optional,
    ) {}
}
