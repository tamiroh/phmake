<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;

use function dirname;
use function escapeshellarg;
use function file_get_contents;
use function getenv;

final readonly class Application
{
    /** @param list<string> $arguments */
    public function run(array $arguments): void
    {
        try {
            $commandLine = new CommandLine($arguments, (string) getenv('MAKEFLAGS'));
            $makefile = $this->createMakefile($commandLine);
            $environment = ['MAKEFLAGS' => $commandLine->makeflags()];
            $expander = new VariableExpander($makefile->variables);
            foreach ($commandLine->variables as $variable) {
                $environment[$variable->name] = $expander->expand('$(' . $variable->name . ')');
            }
            $makefile->run($commandLine->targets, new Shell($environment), new Filesystem(), new Output());
        } catch (CommandFailedException $e) {
            Process::stopWithCommandFailure($e->target, $e->exitCode);
        } catch (ParseException $e) {
            Process::stopWithError($e->reason, "Makefile:$e->lineNumber");
        } catch (MakefileErrorException $e) {
            Process::stopWithError($e->getMessage());
        }
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    private function createMakefile(CommandLine $commandLine): Makefile
    {
        $makefileRaw = @file_get_contents('Makefile');

        if ($makefileRaw === false) {
            Process::stopWithError('No targets specified and no makefile found');
        }

        return new MakefileParser(
            $makefileRaw,
            new SourceFiles(),
            [
                ...Builtins::variables(),
                new Variable(
                    'MAKE',
                    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/phmake'),
                    false,
                ),
            ],
            $commandLine->variables,
            Builtins::rules(),
        )->parse();
    }
}
