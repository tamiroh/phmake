<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use DateTime;
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
        $lastModified = $this->getLastModifiedTimesOfTargets($makefile);
        $shellExec = new class implements ShellExecInterface {
            public function exec(string $command): string
            {
                return (string) shell_exec($command);
            }
        };

        try {
            $makefile->run(array_slice($argv, 1), $lastModified, $shellExec);
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

    /**
     * @param Makefile $makefile
     *
     * @return array<string, DateTime|null>
     */
    public function getLastModifiedTimesOfTargets(Makefile $makefile): array
    {
        $lastModified = [];
        foreach ($makefile->targets as $target) {
            $lastModifiedAsUnixTime = @filemtime($target->name);
            $lastModified[$target->name] = $lastModifiedAsUnixTime === false
                ? null
                : (new DateTime())->setTimestamp($lastModifiedAsUnixTime);
        }
        return $lastModified;
    }
}