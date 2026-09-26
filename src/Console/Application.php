<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Phar;
use Tamiroh\Phmake\Console\Input\CommandLine;
use Tamiroh\Phmake\Console\Input\Usage;
use Tamiroh\Phmake\Console\Input\UsageException;
use Tamiroh\Phmake\Console\Makefile\MakefileLoader;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Console\Output\OutputWriteException;
use Tamiroh\Phmake\Console\Process\ModuleHost;
use Tamiroh\Phmake\Console\Process\RestartFailureException;
use Tamiroh\Phmake\Console\Process\Signals;
use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Diagnostics;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Execution\CommandFailedException;
use Tamiroh\Phmake\Makefile\Execution\InterruptedException;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Parser\ParseException;

use function basename;
use function chdir;
use function dirname;
use function escapeshellarg;
use function fwrite;
use function getcwd;
use function getenv;
use function max;
use function preg_match;

use const PHP_BINARY;
use const STDERR;

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
        $program = Phar::running(false) === '' ? 'phmake' : basename(Phar::running(false));
        $output = new Output(level: $level, program: $program);
        $leaveDirectory = true;
        try {
            $defaults = $this->initialVariables($output);
            $commandLine = new CommandLine(
                $arguments,
                (string) getenv('MAKEFLAGS'),
                (string) getenv('GNUMAKEFLAGS'),
                $defaults,
            );
            if ($commandLine->execution->reporting->enabled('b')) {
                $output->write("phmake (development)\n");
            }
            if ($commandLine->version) {
                $output->write(Usage::version());
                return 0;
            }
            if ($commandLine->input->help) {
                $output->write(Usage::text($program));
                return 0;
            }
            foreach ($commandLine->input->directories as $directory) {
                if (!@chdir($directory)) {
                    throw new MakefileErrorException("Cannot change directory to '$directory'");
                }
            }
            $output = new Output(
                $commandLine->execution->reporting->silent,
                $level,
                $commandLine->execution->parallel,
                $program,
            );
            $printDirectory =
                $commandLine->switches->value('printDirectory')
                ?? !$commandLine->execution->reporting->silent
                    && !$commandLine->execution->question
                    && ($level > 0 || $commandLine->input->directories !== []);
            if ($printDirectory) {
                if ($commandLine->input->restarts < 0) {
                    $output->directory = (string) getcwd();
                } else {
                    $output->writeDirectory(true, (string) getcwd());
                }
            }
            return new MakefileLoader($commandLine, $output, $defaults, $level)->load()->run($commandLine->targets);
        } catch (RestartFailureException $error) {
            $leaveDirectory = false;
            $output->writeWarning($error->getMessage());
            return 127;
        } catch (OutputWriteException $error) {
            $leaveDirectory = false;
            @fwrite(STDERR, $program . ': ' . $error->getMessage() . "\n");
            return 1;
        } catch (InterruptedException $error) {
            throw $error;
        } catch (CommandFailedException|MakefileErrorException $error) {
            Diagnostics::report($error, $output);
            if ($error instanceof UsageException) {
                $output->buffer->write(Usage::text($program), true);
            }
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
            if ($leaveDirectory && $output->directory !== null) {
                $output->writeDirectory(false, $output->directory);
            }
        }
    }

    /**
     * @throws MakefileErrorException
     *
     * @return array<string, Variable>
     */
    private function initialVariables(Output $output): array
    {
        $variables = [];
        foreach (Builtins::variables() as $variable) {
            $variables[$variable->name] = $variable->name === '.FEATURES'
                ? new Variable(
                    '.FEATURES',
                    rtrim($variable->expression . ' ' . ModuleHost::capabilities($output)),
                    false,
                    'default',
                )
                : $variable;
        }
        $executable = Phar::running(false);
        $variables['MAKE'] = new Variable(
            'MAKE',
            $executable === ''
                ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/phmake')
                : (preg_match('~^[a-zA-Z0-9_./-]+$~', $executable) === 1 ? $executable : escapeshellarg($executable)),
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
