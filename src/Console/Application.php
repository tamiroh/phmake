<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use DateTime;
use Tamiroh\Phmake\Exceptions\MakefileException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Parser\MakefileParser;

readonly final class Application
{
    public function run(): void
    {
        global $argv;

        $makefile = $this->createMakefile();
        $lastModified = $this->getLastModifiedTimesOfTargets($makefile);

        try {
            $makefile->run(array_slice($argv, 1), $lastModified);
        } catch (MakefileException $e) {
            Process::stop($e->getMessage());
        }
    }

    private function createMakefile(): Makefile
    {
        $makefileRaw = @file_get_contents('Makefile');

        if ($makefileRaw === false) {
            Process::stop('No targets specified and no makefile found');
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