<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\IO;

use Closure;
use Tamiroh\Phmake\Engine\MakefileErrorException;

/**
 * Scheme evaluation with gmk-expand and gmk-eval in the calling make context.
 */
interface Guile
{
    /**
     * @param Closure(string): string $expand
     * @param Closure(string): void $eval
     *
     * @throws MakefileErrorException
     */
    public function evaluate(string $expression, Closure $expand, Closure $eval): string;
}
