<?php

declare(strict_types=1);

namespace Tamiroh\Phmake;

readonly final class Application
{
    public function run(): void
    {
        global $argv;

        $makefileRaw = file_get_contents('Makefile');
        $makefile = (new Parser($makefileRaw))->parse();

        $makefile->run($argv[1] ?? null);
    }
}