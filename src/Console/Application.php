<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;

use function file_get_contents;

final readonly class Application
{
    /** @param list<string> $arguments */
    public function run(array $arguments): void
    {
        try {
            $this->createMakefile()->run($arguments, new Shell(), new Filesystem(), new Output());
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
    private function createMakefile(): Makefile
    {
        $makefileRaw = @file_get_contents('Makefile');

        if ($makefileRaw === false) {
            Process::stopWithError('No targets specified and no makefile found');
        }

        return new MakefileParser($makefileRaw, new SourceFiles())->parse();
    }
}
