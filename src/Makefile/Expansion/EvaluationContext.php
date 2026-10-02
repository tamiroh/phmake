<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Expansion;

use Closure;
use Tamiroh\Phmake\Makefile\Expansion\LoadedObject\LoadedObjects;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\Reporting\ReportingOptions;
use Tamiroh\Phmake\Makefile\Variable\Environment\EnvironmentState;
use Tamiroh\Phmake\Makefile\Variable\Environment\Exports;
use Tamiroh\Phmake\Makefile\Variable\Variable;

use function in_array;

/**
 * Live evaluation state for one read and its subsequent build.
 * Parsing, makefile remaking, and recipes deliberately share mutations (including eval
 * and shell status). A restart creates a new context; Makefile stores no context.
 * BuildState and SearchState have separate lifetimes owned by Build.
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

    public LoadedObjects $loadedObjects;

    /**
     * @param list<Variable> $variables
     */
    public function __construct(array $variables = [])
    {
        $this->environment = new EnvironmentState();
        $this->loadedObjects = new LoadedObjects();
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
