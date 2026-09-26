<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Diagnostics;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Execution\CommandFailedException;
use Tamiroh\Phmake\Makefile\Execution\InterruptedException;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Parser\ParseException;

use function chdir;
use function dirname;
use function escapeshellarg;
use function getcwd;
use function getenv;
use function max;

use const PHP_BINARY;

final readonly class Application
{
    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): void
    {
        $signals = new Signals();
        $status = 0;
        try {
            $status = $this->execute($arguments);
        } catch (InterruptedException) {
            // Active recipes report their own interruption before the process exits by signal.
        } finally {
            $signals->finish();
        }
        if ($status !== 0) {
            exit($status);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @throws InterruptedException
     */
    private function execute(array $arguments): int
    {
        $level = max(0, (int) getenv('MAKELEVEL'));
        $output = new Output(level: $level);
        $printDirectory = false;
        try {
            $defaults = $this->initialVariables();
            $commandLine = new CommandLine(
                $arguments,
                (string) getenv('MAKEFLAGS'),
                (string) getenv('GNUMAKEFLAGS'),
                $defaults,
            );
            if ($commandLine->version) {
                echo "phmake (development)\n";
                return 0;
            }
            foreach ($commandLine->input->directories as $directory) {
                if (!@chdir($directory)) {
                    throw new MakefileErrorException("Cannot change directory to '$directory'");
                }
            }
            $output = new Output($commandLine->execution->reporting->silent, $level, $commandLine->execution->parallel);
            $printDirectory =
                $commandLine->printDirectory
                ?? !$commandLine->execution->reporting->silent
                    && !$commandLine->execution->question
                    && ($level > 0 || $commandLine->input->directories !== []);
            if ($printDirectory) {
                $output->writeDirectory(true, (string) getcwd());
            }
            return new MakefileLoader($commandLine, $output, $defaults, $level)->load()->run($commandLine->targets);
        } catch (InterruptedException $error) {
            throw $error;
        } catch (CommandFailedException|MakefileErrorException $error) {
            Diagnostics::report($error, $output);
            return 2;
        } catch (ParseException $error) {
            Diagnostics::report(
                new MakefileErrorException(
                    $error->reason,
                    ($commandLine->input->makefiles[0] ?? 'Makefile') . ":$error->lineNumber",
                ),
                $output,
            );
            return 2;
        } finally {
            if ($printDirectory) {
                $output->writeDirectory(false, (string) getcwd());
            }
        }
    }

    /**
     * @return array<string, Variable>
     */
    private function initialVariables(): array
    {
        $variables = [];
        foreach (Builtins::variables() as $variable) {
            $variables[$variable->name] = $variable;
        }
        $variables['MAKE'] = new Variable(
            'MAKE',
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/phmake'),
            false,
            'default',
        );
        $variables['MAKE_COMMAND'] = new Variable('MAKE_COMMAND', $variables['MAKE']->expression, false, 'default');
        foreach (getenv() as $name => $value) {
            if ($name !== 'SHELL' && $name !== 'MAKE_RESTARTS') {
                $variables[$name] = new Variable($name, $value, origin: 'environment');
            }
        }
        return $variables;
    }
}
