<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Exceptions\MakefileException;
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

        try {
            $makefile->run($argv[1] ?? null);
        } catch (MakefileException $e) {
            Process::stop($e->getMessage());
        }
    }
}