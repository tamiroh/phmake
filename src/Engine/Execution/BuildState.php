<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution;

use Closure;
use Generator;
use Tamiroh\Phmake\Engine\Execution\Files\FileState;
use Tamiroh\Phmake\Engine\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Engine\IO\Output;
use Tamiroh\Phmake\Engine\Makefile;
use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Reporting\Diagnostics;
use Tamiroh\Phmake\Engine\Rule\BuildRule;
use Tamiroh\Phmake\Engine\Rule\Target;
use Tamiroh\Phmake\Engine\Search\SearchState;

/**
 * @internal
 */
final class BuildState
{
    /** @var array<string, FileState> */
    public array $files = [];

    /** @var array<string, UpdateResult> */
    public array $recipes = [];

    /** @var array<string, array<string, true>> */
    public array $dropped = [];

    public bool $remaking = false;

    public bool $needsUpdate = false;

    public bool $failed = false;

    public bool $waiting = false;

    /** @var ?Closure(): void */
    public ?Closure $beforeRecipe = null;

    public function __construct(
        public readonly int $restarts = 0,
    ) {}

    /**
     * @return Generator<int, array{string, BuildRule, bool, ?UpdateResult}>
     */
    public function databaseFiles(Makefile $makefile, SearchState $search): Generator
    {
        $names = $makefile->targetsByName + $search->targets + $search->mentioned + $this->files;
        $printed = [];
        foreach ($names as $name => $_) {
            $target = $search->targets[$name] ?? $makefile->targetsByName[$name] ?? new Target("{$name}");
            if (isset($printed[$target->name])) {
                continue;
            }
            $printed[$target->name] = true;
            $file = $this->files[$name] ?? null;
            foreach ($target->rules === [] ? [new BuildRule()] : $target->rules as $index => $rule) {
                yield [
                    $target->name,
                    $rule,
                    isset($search->searched[$target->name]) || isset($search->searched[$name]),
                    $rule->doubleColon ? $file->ruleResults[$index] ?? null : $file?->result,
                ];
            }
        }
    }

    public function failure(MakefileErrorException|CommandFailedException $error, Output $output): UpdateResult
    {
        if (!$this->remaking) {
            Diagnostics::report($error, $output, stop: false);
        }
        return new UpdateResult(failure: $error);
    }
}
