<?php

declare(strict_types=1);

namespace Tamiroh\Phmake;

use Tamiroh\Phmake\Parser\MakefileParser;

readonly final class Application
{
    public function run(): void
    {
        global $argv;

        $makefileRaw = @file_get_contents('Makefile');

        if ($makefileRaw === false) {
            Process::stop('No targets specified and no makefile found');
        }

        $makefile = (new MakefileParser($makefileRaw))->parse();

        $makefile->run($argv[1] ?? null);
    }
}