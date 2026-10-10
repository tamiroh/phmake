<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception as MockException;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Engine\Engine;
use Tamiroh\Phmake\Engine\Evaluation\Evaluator;
use Tamiroh\Phmake\Engine\Evaluation\MakefileSources;
use Tamiroh\Phmake\Engine\Execution\Build;
use Tamiroh\Phmake\Engine\Execution\ExecutionOptions;
use Tamiroh\Phmake\Engine\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Engine\Expansion\EvaluationContext;
use Tamiroh\Phmake\Engine\Expansion\VariableExpander;
use Tamiroh\Phmake\Engine\Invocation\InvocationOptions;
use Tamiroh\Phmake\Engine\IO\IntermediateDeletionOrder;
use Tamiroh\Phmake\Engine\IO\Shell;
use Tamiroh\Phmake\Engine\IO\TargetUpdates;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\RunResult;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;

final class EngineTest extends TestCase
{
    /**
     * @throws MockException
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    #[Test]
    public function reportsFailureWhenKeepingGoing(): void
    {
        // Arrange
        $filesystem = new FakeFilesystem(['Makefile' => "all:\n\tfail\n"]);
        $shell = $this->createMock(Shell::class);
        $shell->expects(self::once())->method('exec')->with('fail')->willReturn(1);
        $execution = new ExecutionOptions();
        $execution->keepGoing = true;
        [$engine, $sources] = $this->engine($filesystem, $shell, $execution);

        // Act
        $result = $engine->run($sources->read);

        // Assert
        self::assertSame(RunResult::Failed, $result);
    }

    /**
     * @throws MockException
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    #[Test]
    public function reportsOutOfDateWithoutExecutingRecipes(): void
    {
        // Arrange
        $filesystem = new FakeFilesystem(['Makefile' => "all:\n\tbuild\n"]);
        $shell = $this->createMock(Shell::class);
        $shell->expects(self::never())->method('exec');
        $execution = new ExecutionOptions();
        $execution->question = true;
        [$engine, $sources] = $this->engine($filesystem, $shell, $execution);

        // Act
        $result = $engine->run($sources->read);

        // Assert
        self::assertSame(RunResult::OutOfDate, $result);
    }

    /**
     * @throws MockException
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    #[Test]
    public function requestsReevaluationBeforeRunningGoalsWhenAnIncludeIsGenerated(): void
    {
        // Arrange
        $filesystem = new FakeFilesystem([
            'Makefile' => "include generated.mk\nall:\n\tbuild\ngenerated.mk:\n\tgenerate\n",
        ]);
        $shell = $this->createMock(Shell::class);
        $shell
            ->expects(self::once())
            ->method('exec')
            ->with('generate')
            ->willReturnCallback(static function () use ($filesystem): int {
                $filesystem->write('generated.mk', "VALUE = generated\n", false);
                return 0;
            });
        $execution = new ExecutionOptions();
        $execution->dryRun = true;
        [$engine, $sources, $context] = $this->engine($filesystem, $shell, $execution);

        // Act
        $result = $engine->run($sources->read, ['all']);

        // Assert
        self::assertSame(RunResult::NeedsReevaluation, $result);
        self::assertTrue($filesystem->exists('generated.mk'));
        self::assertSame('n', new VariableExpander($context)->expand('$(MAKEFLAGS)'));
    }

    /**
     * @throws MockException
     * @throws MakefileErrorException
     */
    #[Test]
    public function restoresFlagsAndPropagatesMakefileRecipeFailure(): void
    {
        // Arrange
        $filesystem = new FakeFilesystem([
            'Makefile' => "include generated.mk\nall:\n\tbuild\ngenerated.mk:\n\tfail\n",
        ]);
        $shell = $this->createMock(Shell::class);
        $shell->expects(self::once())->method('exec')->with('fail')->willReturn(1);
        $execution = new ExecutionOptions();
        $execution->dryRun = true;
        [$engine, $sources, $context] = $this->engine($filesystem, $shell, $execution);

        $failure = null;

        // Act
        try {
            $engine->run($sources->read, ['all']);
        } catch (CommandFailedException $error) {
            $failure = $error;
        }

        // Assert
        self::assertInstanceOf(CommandFailedException::class, $failure);
        self::assertSame('generated.mk', $failure->target);
        self::assertSame('n', new VariableExpander($context)->expand('$(MAKEFLAGS)'));
    }

    /**
     * @throws MockException
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    #[Test]
    public function runsExplicitGoalsInsteadOfTheDefault(): void
    {
        // Arrange
        $filesystem = new FakeFilesystem(['Makefile' => "all:\n\tbuild-default\nother:\n\tbuild-other\n"]);
        $shell = $this->createMock(Shell::class);
        $shell->expects(self::once())->method('exec')->with('build-other')->willReturn(0);
        [$engine, $sources] = $this->engine($filesystem, $shell);

        // Act
        $result = $engine->run($sources->read, ['other']);

        // Assert
        self::assertSame(RunResult::Succeeded, $result);
    }

    /**
     * @throws MockException
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    #[Test]
    public function runsTheDefaultGoal(): void
    {
        // Arrange
        $filesystem = new FakeFilesystem(['Makefile' => "all:\n\tbuild-default\nother:\n\tbuild-other\n"]);
        $shell = $this->createMock(Shell::class);
        $shell->expects(self::once())->method('exec')->with('build-default')->willReturn(0);
        [$engine, $sources] = $this->engine($filesystem, $shell);

        // Act
        $result = $engine->run($sources->read);

        // Assert
        self::assertSame(RunResult::Succeeded, $result);
    }

    /**
     * @throws MockException
     * @throws MakefileErrorException
     *
     * @return array{Engine, MakefileSources, EvaluationContext}
     */
    private function engine(
        FakeFilesystem $filesystem,
        Shell $shell,
        ExecutionOptions $execution = new ExecutionOptions(),
    ): array {
        $output = new FakeOutput();
        $sources = new MakefileSources($filesystem, ['Makefile']);
        [$makefile, $context] = new Evaluator(
            $sources,
            output: $output,
            shell: $shell,
            filesystem: $filesystem,
        )->evaluate();
        $deletionOrder = self::createStub(IntermediateDeletionOrder::class);
        $deletionOrder->method('names')->willReturn([]);
        $build = new Build(
            $makefile,
            $context,
            $shell,
            $filesystem,
            $output,
            self::createStub(TargetUpdates::class),
            $deletionOrder,
            $execution,
        );
        return [
            new Engine($build, $context, $filesystem, $output, new InvocationOptions(), $execution),
            $sources,
            $context,
        ];
    }
}
