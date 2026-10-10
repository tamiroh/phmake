<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine;

use Tamiroh\Phmake\Engine\Execution\Build;
use Tamiroh\Phmake\Engine\Execution\ExecutionOptions;
use Tamiroh\Phmake\Engine\Execution\MakefileRemake;
use Tamiroh\Phmake\Engine\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Engine\Expansion\EvaluationContext;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\Invocation\InvocationOptions;
use Tamiroh\Phmake\Engine\Invocation\MakeFlags;
use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\Output;

/**
 * Executes one evaluated makefile, requesting a reread when its inputs change.
 */
final readonly class Engine
{
    public function __construct(
        private Build $build,
        private EvaluationContext $context,
        private Filesystem $filesystem,
        private Output $output,
        private InvocationOptions $options,
        private ExecutionOptions $execution,
    ) {}

    /**
     * @param list<ReadFile> $read
     * @param list<string> $targets
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    public function run(array $read, array $targets = [], int $restarts = 0): RunResult
    {
        MakeFlags::define(
            $this->options,
            $this->execution,
            $this->context->variables,
            $this->context->reading->posix,
            $restarts,
        );
        try {
            $remade = new MakefileRemake(
                $this->build,
                $this->filesystem,
                $this->output,
                $this->execution->keepGoing,
            )->run($read);
            if (!$remade) {
                $this->context->loadedObjects->reload(new VariableExpander($this->context, $this->output));
            }
        } catch (MakefileErrorException|CommandFailedException $error) {
            $this->build->cleanup();
            throw $error;
        } finally {
            MakeFlags::define(
                $this->options,
                $this->execution,
                $this->context->variables,
                $this->context->reading->posix,
            );
        }
        if ($remade) {
            $this->build->cleanup();
            return RunResult::NeedsReevaluation;
        }
        return $this->build->run($targets);
    }
}
