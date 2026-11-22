<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Commands;

use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Console\Output\OutputWriteException;
use Tamiroh\Phmake\Formatter\Formatter;
use Tamiroh\Phmake\Parser\MakefileParser;

use function array_shift;
use function count;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_file;
use function str_starts_with;
use function strlen;

use const STDERR;

final readonly class FormatCommand
{
    private const string USAGE = "Usage: phmake --format [--check] [FILE]\n";

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $output = new Output();
        try {
            $first = $arguments[0] ?? null;
            if (count($arguments) === 1 && ($first === '--help' || $first === '-h')) {
                $output->write(
                    self::USAGE
                    . "Format FILE in place without evaluating it.\n"
                    . "With --check, leave FILE unchanged and exit 1 if formatting is needed.\n",
                );
                return 0;
            }
            $check = $first === '--check';
            if ($check) {
                array_shift($arguments);
            }
            $file = $arguments[0] ?? $this->defaultFile();
            if (count($arguments) > 1 || str_starts_with($file, '-')) {
                @fwrite(STDERR, self::USAGE);
                return 2;
            }
            $source = @file_get_contents($file);
            if ($source === false) {
                @fwrite(STDERR, "phmake: cannot read '{$file}'\n");
                return 2;
            }
            $formatted = new Formatter()->format(MakefileParser::parse($source, $file));
            if ($formatted === $source) {
                return 0;
            }
            if ($check) {
                $output->write("Would reformat {$file}\n");
                return 1;
            }
            if (@file_put_contents($file, $formatted) !== strlen($formatted)) {
                @fwrite(STDERR, "phmake: cannot write '{$file}'\n");
                return 2;
            }
            return 0;
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
