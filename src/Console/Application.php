<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\Filesystem;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\MakefileUpToDateException;
use Tamiroh\Phmake\Makefile\Output;
use Tamiroh\Phmake\Makefile\Shell;
use Tamiroh\Phmake\Parser\MakefileParser;

final readonly class Application
{
    public function run(): void
    {
        /** @var list<string> $argv */
        global $argv;

        $makefile = $this->createMakefile();
        $shell = new class implements Shell {
            public function exec(string $command): string
            {
                return (string) shell_exec($command);
            }
        };
        $filesystem = new class implements Filesystem {
            public function exists(string $path): bool
            {
                return file_exists($path);
            }

            public function lastModified(string $path): ?int
            {
                $result = @filemtime($path);

                return $result === false ? null : $result;
            }
        };
        $output = new class implements Output {
            public function write(string $text): void
            {
                echo $text;
            }

            public function writeLine(string $line): void
            {
                echo $line . PHP_EOL;
            }
        };

        try {
            $makefile->run(array_slice($argv, 1), $shell, $filesystem, $output);
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
