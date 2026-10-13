<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Commands;

use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Console\Output\OutputWriteException;
use Tamiroh\Phmake\Linter\Linter;
use Tamiroh\Phmake\Linter\Rules\MissingPhony;
use Tamiroh\Phmake\Parser\MakefileParser;

use function count;
use function file_get_contents;
use function fwrite;
use function is_file;
use function str_starts_with;

use const STDERR;

final readonly class LintCommand
{
    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $output = new Output();
        try {
            $first = $arguments[0] ?? null;
            if (count($arguments) === 1 && ($first === '--help' || $first === '-h')) {
                $output->write("Usage: phmake --lint [FILE]\nReport problems in FILE without evaluating it.\n");
                return 0;
            }
            $file = $arguments[0] ?? $this->defaultFile();
            if (count($arguments) > 1 || str_starts_with($file, '-')) {
                @fwrite(STDERR, "Usage: phmake --lint [FILE]\n");
                return 2;
            }
            $source = @file_get_contents($file);
            if ($source === false) {
                @fwrite(STDERR, "phmake: cannot read '{$file}'\n");
                return 2;
            }
            $diagnostics = new Linter([new MissingPhony()])->lint(MakefileParser::parse($source, $file));
            foreach ($diagnostics as $diagnostic) {
                $span = $diagnostic->span;
                $output->write(
                    "{$span->file}:{$span->startLine}:{$span->startColumn}: {$diagnostic->message} [{$diagnostic->rule}]\n",
                );
            }
            return $diagnostics === [] ? 0 : 1;
        } catch (OutputWriteException $error) {
            @fwrite(STDERR, 'phmake: ' . $error->getMessage() . "\n");
            return 2;
        }
    }

    private function defaultFile(): string
    {
        foreach (['GNUmakefile', 'makefile', 'Makefile'] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        return 'Makefile';
    }
}
