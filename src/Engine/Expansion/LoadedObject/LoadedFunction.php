<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Expansion\LoadedObject;

/**
 * @internal
 */
final readonly class LoadedFunction
{
    public function __construct(
        public LoadedObject $object,
        public int $minimum,
        public int $maximum,
        public bool $expand,
    ) {}
}
