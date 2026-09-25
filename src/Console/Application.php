<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Execution\CommandFailedException;
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
                return;
            }
            foreach ($commandLine->input->directories as $directory) {
                if (!@chdir($directory)) {
                    throw new MakefileErrorException("Cannot change directory to '$directory'");
                }
            }
            $level = max(0, (int) getenv('MAKELEVEL'));
            $output = new Output($commandLine->silent, $level);
            $printDirectory =
                $commandLine->printDirectory
                ?? !$commandLine->silent && ($level > 0 || $commandLine->input->directories !== []);
            if ($printDirectory) {
                $output->writeDirectory(true, (string) getcwd());
            }
            try {
                new MakefileLoader($commandLine, $output, $defaults, $level)->load()->run($commandLine->targets);
            } finally {
                if ($printDirectory) {
                    $output->writeDirectory(false, (string) getcwd());
                }
            }
        } catch (CommandFailedException $e) {
            Process::stopWithCommandFailure($e->target, $e->exitCode);
        } catch (ParseException $e) {
            Process::stopWithError($e->reason, ($commandLine->input->makefiles[0] ?? 'Makefile') . ":$e->lineNumber");
        } catch (MakefileErrorException $e) {
            Process::stopWithError($e->getMessage(), $e->source ?? 'phmake');
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
        foreach (getenv() as $name => $value) {
            if ($name !== 'SHELL' && $name !== 'MAKE_RESTARTS') {
                $variables[$name] = new Variable($name, $value, origin: 'environment');
            }
        }
        return $variables;
    }
}
