<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation\LoadedObject;

use Tamiroh\Phmake\Makefile\IO\DynamicObject;

/**
 * @internal
 */
final class LoadedObject
{
    public ?DynamicObject $instance = null;

    public bool $keep = false;

    public bool $reload = false;

    public function __construct(
        public readonly string $path,
        public readonly string $setup,
        public readonly ?string $source,
        public readonly bool $optional,
    ) {}
}
