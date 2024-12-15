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

        $lastModified = [];
        foreach ($makefile->targets as $target) {
            $lastModifiedAsUnixTime = @filemtime($target->name);
            $lastModified[$target->name] = $lastModifiedAsUnixTime === false
                ? null
                : (new DateTime())->setTimestamp($lastModifiedAsUnixTime);
        }

        try {
            $makefile->run($argv[1] ?? null, $lastModified);
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
}