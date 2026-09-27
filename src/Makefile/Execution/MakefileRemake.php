<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use LogicException;
use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\SourceFiles;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\ReadFile;
use Tamiroh\Phmake\Makefile\Reporting\Diagnostics;

/**
 * Updates the makefiles that were read and decides whether make must restart.
 */
final readonly class MakefileRemake
{
    public function __construct(
        private Build $build,
        private SourceFiles $files,
        private Output $output,
        private bool $keepGoing,
    ) {}

    /**
     * @param list<ReadFile> $read
     *
     * @throws MakefileErrorException
     * @throws CommandFailedException
     *
     * @return bool whether any makefile changed and make must read them again
     */
    public function run(array $read): bool
    {
        $names = [];
        $inputs = [];
        $unreadable = [];
        foreach ($read as $file) {
            if ($file->rebuild) {
                $inputs[$file->path] = $file;
            }
        }
        foreach ($inputs as $file) {
            $names[] = $file->path;
            if ($file->text === null) {
                $unreadable[] = $file->path;
            }
        }
        // Take subsecond times in one batch rather than once per file while parsing.
        $modifiedTimes = $this->files->modifiedTimes($names);
        $errors = $this->build->remake($names, $unreadable);
        foreach ($this->files->readMany($names) as $path => $contents) {
            $file = $inputs[$path] ?? throw new LogicException('Unexpected makefile in batch read');
            if (
                $contents->text !== null
                && ($contents->text !== $file->text || $contents->modifiedAt !== ($modifiedTimes[$path] ?? null))
            ) {
                return true;
            }
        }
        foreach ($read as $file) {
            if (
                !$file->optional
                && $file->text === null
                && $file->modifiedAt !== null
                && $this->files->read($file->path)->text === null
                && !isset($errors[$file->path])
            ) {
                $errors[$file->path] = new MakefileErrorException("No rule to make target '{$file->path}'");
            }
            if (!$file->optional && isset($errors[$file->path])) {
                if (
                    $errors[$file->path] instanceof CommandFailedException
                    && $errors[$file->path]->target !== $file->path
                    && ($inputs[$errors[$file->path]->target]->optional ?? false)
                ) {
                    $this->output->writeWarning("Failed to remake makefile '{$file->path}'.", $file->source);
                    $errors[$file->path]->reported = true;
                    throw $errors[$file->path];
                }
                if ($file->text === null && $file->source !== null) {
                    $this->output->writeWarning(
                        $file->path . ': ' . ($file->error ?? 'No such file or directory'),
                        $file->source,
                    );
                }
                if ($errors[$file->path] instanceof UnremadeMakefileException) {
                    $errors[$file->path]->reported = true;
                    throw $errors[$file->path];
                }
                if ($this->keepGoing) {
                    Diagnostics::report($errors[$file->path], $this->output, false);
                    $this->output->writeWarning("Failed to remake makefile '{$file->path}'.", $file->source);
                }
                throw $errors[$file->path];
            }
        }
        return false;
    }
}
