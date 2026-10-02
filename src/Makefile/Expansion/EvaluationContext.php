<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Expansion;

use Tamiroh\Phmake\Makefile\Expansion\LoadedObject\LoadedObjects;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Guile;
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
    /**
     * Global definitions, including changes from eval and .SHELLSTATUS.
     *
     * @var array<string, Variable>
     */
    public array $variables = [];

    /** Initial reading, .POSIX and reading eval results share one makefile-reading state. */
    public MakefileReading $reading;

    /** Shell expansion and assignments share exports and shell status with recipes. */
    public ?Shell $shell = null;

    /** File functions, wildcard expansion and load directive existence checks. */
    public ?Filesystem $filesystem = null;

    /** Export directives are shared with Makefile definitions and may change through eval. */
    public Exports $exports;

    /** Warning and trace options for expansions at every phase. */
    public ReportingOptions $reporting;

    /** Inherited environment and protection against recursive shell expansion in exports. */
    public EnvironmentState $environment;

    /** Loaded object definitions and registered functions, retained across makefile remaking. */
    public LoadedObjects $loadedObjects;

    /** Scheme evaluation; interpreter creation and lifetime are owned by the host. */
    public ?Guile $guile = null;

    /**
     * @param list<Variable> $variables
     */
    public function __construct(array $variables = [])
    {
        $this->reading = new MakefileReading();
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
