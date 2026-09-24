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

use function dirname;
use function escapeshellarg;
use function file_get_contents;
use function getenv;
use function substr_count;

final readonly class Application
{
    /** @param list<string> $arguments */
    public function run(array $arguments): void
    {
        try {
            $commandLine = new CommandLine($arguments, (string) getenv('MAKEFLAGS'));
            if ($commandLine->version) {
                echo "phmake (development)\n";
                return;
            }
            $output = new Output($commandLine->silent);
            $makefile = $this->createMakefile($commandLine, $output);
            $environment = ['MAKEFLAGS' => $commandLine->makeflags()];
            $makefile->run($commandLine->targets, new Shell($environment), new Filesystem(), $output);
        } catch (CommandFailedException $e) {
            Process::stopWithCommandFailure($e->target, $e->exitCode);
        } catch (ParseException $e) {
            Process::stopWithError($e->reason, ($commandLine->makefiles[0] ?? 'Makefile') . ":$e->lineNumber");
        } catch (MakefileErrorException $e) {
            Process::stopWithError($e->getMessage(), $e->source ?? 'phmake');
        }
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    private function createMakefile(CommandLine $commandLine, Output $output): Makefile
    {
        $makefileRaw = '';
        $sources = [];
        foreach ($commandLine->makefiles === [] ? ['Makefile'] : $commandLine->makefiles as $path) {
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

        $environmentVariables = [];
        foreach (getenv() as $name => $value) {
            if ($name !== 'SHELL') {
                $environmentVariables[] = new Variable($name, $value, origin: 'environment');
            }
        }
        return new MakefileParser(
            $makefileRaw,
            new SourceFiles(),
            [
                ...Builtins::variables(),
                ...$environmentVariables,
                new Variable('MAKEFLAGS', $commandLine->makeflags(), false),
                new Variable(
                    'MAKE',
                    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/phmake'),
                    false,
                    'default',
                ),
            ],
            $commandLine->variables,
            Builtins::rules(),
            $output,
            $sources,
        )->parse();
    }
}
