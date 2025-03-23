<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\MakefileUpToDateException;
use Tamiroh\Phmake\Makefile\ShellExecInterface;
use Tamiroh\Phmake\Parser\MakefileParser;

readonly final class Application
{
    public function run(): void
    {
        global $argv;

        $makefile = $this->createMakefile();
        $shellExec = new class implements ShellExecInterface {
            public function exec(string $command): string
            {
                return (string) shell_exec($command);
            }
        };

        try {
            $makefile->run(array_slice($argv, 1), $shellExec);
        } catch (MakefileErrorException $e) {
            Process::stopWithError($e->getMessage());
        } catch (MakefileUpToDateException $e) {
            Process::stopWithInfo("`$e->target' is up to date");
        }
    }

    private function createMakefile(): Makefile
    {
        $makefileRaw = @file_get_contents('Makefile');

        if ($makefileRaw === false) {
            Process::stopWithError('No targets specified and no makefile found');
        }

        return (new MakefileParser($makefileRaw))->parse();
    }
}