<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Closure;
use Override;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Makefile\IO\Guile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * A persistent Scheme interpreter, independent of the hosts for load directives.
 */
final class ModuleGuile implements Guile
{
    private ?ModuleHost $host = null;

    public function __construct(
        private readonly string $executable,
        private readonly Output $output,
    ) {}

    /**
     * @param Closure(string): string $expand
     * @param Closure(string): void $eval
     *
     * @throws MakefileErrorException
     */
    #[Override]
    public function evaluate(string $expression, Closure $expand, Closure $eval): string
    {
        $this->host ??= new ModuleHost($this->executable, $this->output);
        return $this->host->guile($expression, $expand, $eval);
    }
}
