<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use Closure;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\ReportingOptions;

use function in_array;

/**
 * Mutable global definitions shared by parsing, expansion, and recipe execution.
 */
final class EvaluationContext
{
    /** @var array<string, Variable> */
    public array $variables = [];

    /** @var Closure(string, VariableExpander): void|null */
    public ?Closure $evaluate = null;

    public bool $reading = true;

    public bool $posix = false;

    public ?Shell $shell = null;

    public ?Filesystem $filesystem = null;

    public Exports $exports;

    public ReportingOptions $reporting;

    public EnvironmentState $environment;

    public Modules $modules;

    /**
     * @param list<Variable> $variables
     */
    public function __construct(array $variables = [])
    {
        $this->environment = new EnvironmentState();
        $this->modules = new Modules();
        $this->exports = new Exports();
        $this->reporting = new ReportingOptions();
        foreach ($variables as $variable) {
            $this->variables[$variable->name] = $variable;
            if (in_array($variable->origin, ['environment', 'environment override'], true)) {
                $this->environment->inherited[$variable->name] = $variable->expression;
            }
        }
    }
}
