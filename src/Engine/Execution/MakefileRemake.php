<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution;

use Tamiroh\Phmake\Engine\Execution\Internal\UnremadeMakefileException;
use Tamiroh\Phmake\Engine\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Engine\IO\Filesystem;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\ReadFile;
use Tamiroh\Phmake\Engine\Reporting\Diagnostics;

/**
 * Updates the makefiles that were read and decides whether make must restart.
 */
final readonly class MakefileRemake
{
    public function __construct(
        private Build $build,
        private Filesystem $filesystem,
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
        // As in GNU make, only makefiles whose times change while remaking restart make.
        $before = $this->filesystem->modifiedTimes($names);
        $errors = $this->build->remake($names, $unreadable);
        foreach ($this->filesystem->modifiedTimes($names) as $path => $modifiedAt) {
            if ($modifiedAt !== null && $modifiedAt !== ($before[$path] ?? null)) {
                return true;
            }
        }
        foreach ($read as $file) {
            if (
                !$file->optional
                && $file->text === null
                && $file->modifiedAt !== null
                && $this->filesystem->read($file->path)['text'] === null
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
