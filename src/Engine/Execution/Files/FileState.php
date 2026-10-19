<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Files;

use Tamiroh\Phmake\Engine\Execution\UpdateResult;

/**
 * Observations and update completion belong to a file, not each spelling of its name.
 *
 * @internal
 */
final class FileState
{
    /** @var array<string, true> */
    public array $names = [];

    public bool $observed = false;

    public ?int $modifiedAt = null;

    public ?UpdateResult $result = null;

    public bool $simulated = false;

    public function __construct(
        public string $path,
    ) {}
}
