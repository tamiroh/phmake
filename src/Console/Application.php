<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\MakefileUpToDateException;
use Tamiroh\Phmake\Parser\MakefileParser;

final readonly class Application
{
    public function run(): void
    {
        /** @var list<string> $argv */
        global $argv;

        try {
            $this->createMakefile()->run(array_slice($argv, 1), new Shell(), new Filesystem(), new Output());
        } catch (CommandFailedException $e) {
            Process::stopWithCommandFailure($e->target, $e->exitCode);
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

        return new MakefileParser($makefileRaw)->parse();
    }
}
