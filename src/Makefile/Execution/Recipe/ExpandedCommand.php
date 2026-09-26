<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution\Recipe;

use Tamiroh\Phmake\Makefile\Execution\ExecutionOptions;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\RecipeOutput;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function explode;
use function ltrim;
use function rtrim;
use function str_contains;
use function str_replace;
use function strlen;
use function strspn;
use function substr;
use function trim;

final readonly class ExpandedCommand
{
    public function __construct(
        public string $expression,
        private string $prefix = '',
        private bool $recursive = false,
        public ?string $source = null,
    ) {}

    /**
     * @throws MakefileErrorException
     */
    public function run(
        Shell $shell,
        Output $output,
        ExecutionOptions $options = new ExecutionOptions(),
        bool $oneShell = false,
        ?string $target = null,
    ): CommandResult {
        if ($oneShell) {
            return $this->runLine($this->expression, $shell, $output, $options, $target);
        }
        $active = false;
        $simulated = false;
        $needsUpdate = false;
        $pending = '';
        foreach (explode("\n", $this->expression) as $line) {
            $pending .= $line;
            if (((strlen($line) - strlen(rtrim($line, '\\'))) % 2) === 1) {
                $pending .= "\n";
                continue;
            }
            $result = $this->runLine($pending, $shell, $output, $options, $target);
            $active = $active || $result->active;
            $simulated = $simulated || $result->simulated;
            $needsUpdate = $needsUpdate || $result->needsUpdate;
            if ($result->exitCode !== 0) {
                return $result;
            }
            $pending = '';
        }
        return $pending === ''
            ? new CommandResult($active, $simulated, needsUpdate: $needsUpdate)
            : $this->runLine($pending, $shell, $output, $options, $target);
    }

    /**
     * @throws MakefileErrorException
     */
    private function runLine(
        string $line,
        Shell $shell,
        Output $output,
        ExecutionOptions $options,
        ?string $target,
    ): CommandResult {
        $expanded = ltrim($line);
        $prefixLength = strspn($expanded, "@-+ \t");
        $prefix = $this->prefix . substr($expanded, 0, $prefixLength);
        $expanded = ltrim(substr($expanded, $prefixLength));
        if (trim(str_replace("\\\n", '', $expanded)) === '') {
            return new CommandResult();
        }
        $recursive = $this->recursive || str_contains($prefix, '+');
        if ($output instanceof RecipeOutput) {
            $output->beginCommand($recursive);
        }
        try {
            if ($options->question && !$recursive) {
                return new CommandResult(true, true, needsUpdate: true);
            }
            if (
                $options->dryRun && !$options->touch
                || $options->reporting->print && (!$options->touch || $recursive)
                || !$options->reporting->silent && !str_contains($prefix, '@') && (!$options->touch || $recursive)
            ) {
                $output->write($expanded . "\n");
            }
            if (!$recursive && ($options->dryRun || $options->touch)) {
                return new CommandResult(true, true);
            }
            $exitCode = $shell->exec(
                $expanded,
                ignoreErrors: $options->ignoreErrors || str_contains($prefix, '-'),
                recursive: $recursive,
            );
            if ($exitCode === 1 && $options->question) {
                return new CommandResult(true, true, needsUpdate: true);
            }
            if ($exitCode !== 0 && ($options->ignoreErrors || str_contains($prefix, '-'))) {
                $output->writeWarning(
                    (
                        $target === null
                            ? "Error $exitCode"
                            : new CommandFailedException($target, $exitCode, $this->source)->getMessage()
                    ) . ' (ignored)',
                );
                return new CommandResult(true);
            }
            return new CommandResult(true, exitCode: $exitCode);
        } finally {
            if ($output instanceof RecipeOutput) {
                $output->endCommand();
            }
        }
    }
}
