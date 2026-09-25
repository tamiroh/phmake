<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;

use function array_values;
use function chdir;
use function dirname;
use function escapeshellarg;
use function file_get_contents;
use function getcwd;
use function getenv;
use function implode;
use function in_array;
use function is_file;
use function max;
use function scandir;
use function substr_count;

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
            foreach ($commandLine->directories as $directory) {
                if (!@chdir($directory)) {
                    throw new MakefileErrorException("Cannot change directory to '$directory'");
                }
            }
            $level = max(0, (int) getenv('MAKELEVEL'));
            $output = new Output($commandLine->silent, $level);
            $printDirectory =
                $commandLine->printDirectory
                ?? !$commandLine->silent && ($level > 0 || $commandLine->directories !== []);
            if ($printDirectory) {
                $output->writeDirectory(true, (string) getcwd());
            }
            try {
                $makefile = $this->createMakefile($commandLine, $output, $defaults, $level);
                $makefile->run(
                    $commandLine->targets,
                    new Shell(),
                    new Filesystem(),
                    new Output($commandLine->silent, $level),
                );
            } finally {
                if ($printDirectory) {
                    $output->writeDirectory(false, (string) getcwd());
                }
            }
        } catch (CommandFailedException $e) {
            Process::stopWithCommandFailure($e->target, $e->exitCode);
        } catch (ParseException $e) {
            Process::stopWithError($e->reason, ($commandLine->makefiles[0] ?? 'Makefile') . ":$e->lineNumber");
        } catch (MakefileErrorException $e) {
            Process::stopWithError($e->getMessage(), $e->source ?? 'phmake');
        }
    }

    /**
     * @param array<string, Variable> $defaults
     *
     * @throws MakefileErrorException
     * @throws ParseException
     */
    private function createMakefile(CommandLine $commandLine, Output $output, array $defaults, int $level): Makefile
    {
        $makefileRaw = '';
        $sources = [];
        foreach ($commandLine->makefiles === [] ? $this->defaultMakefiles() : $commandLine->makefiles as $path) {
            $source = @file_get_contents($path === '-' ? 'php://stdin' : $path);
            if ($source === false) {
                Process::stopWithError(
                    $commandLine->makefiles === []
                        ? 'No targets specified and no makefile found'
                        : "Makefile `$path' not found",
                );
            }
            $sources[substr_count($makefileRaw, "\n") + 1] = $path;
            $makefileRaw .= $source . "\n";
        }

        foreach ($defaults as $name => $variable) {
            if (
                $commandLine->noBuiltinVariables
                && $variable->origin === 'default'
                && $name !== 'SHELL'
                && $name !== 'MAKE'
            ) {
                unset($defaults[$name]);
            } elseif ($commandLine->environmentOverrides && $variable->origin === 'environment') {
                $defaults[$name] = new Variable(
                    $name,
                    $variable->expression,
                    $variable->recursive,
                    'environment override',
                );
            }
        }
        return new MakefileParser(
            $makefileRaw,
            new SourceFiles(),
            [
                ...array_values($defaults),
                new Variable('MAKEFLAGS', $commandLine->makeflags(), false),
                ...(isset($defaults['GNUMAKEFLAGS']) ? [new Variable('GNUMAKEFLAGS', '', false, 'environment')] : []),
                new Variable('MAKELEVEL', (string) $level, false, 'environment'),
                new Variable('CURDIR', (string) getcwd(), false),
                ...(
                    $commandLine->targets === []
                        ? []
                        : [new Variable('MAKECMDGOALS', implode(' ', $commandLine->targets), false, 'default')]
                ),
            ],
            $commandLine->variables,
            $commandLine->noBuiltinRules ? [] : Builtins::rules(),
            $output,
            $sources,
            $commandLine,
            new Shell(),
            new Filesystem(),
        )->parse();
    }

    /**
     * @return list<string>
     */
    private function defaultMakefiles(): array
    {
        $entries = scandir('.');
        if ($entries === false) {
            return [];
        }
        foreach (['GNUmakefile', 'makefile', 'Makefile'] as $path) {
            if (in_array($path, $entries, true) && is_file($path)) {
                return [$path];
            }
        }
        return [];
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
            if ($name !== 'SHELL') {
                $variables[$name] = new Variable($name, $value, origin: 'environment');
            }
        }
        return $variables;
    }
}
