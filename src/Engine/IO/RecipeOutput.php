<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\IO;

/**
 * Target and recipe-line boundaries used by --output-sync.
 */
interface RecipeOutput extends Output
{
    public function beginCommand(bool $recursive): void;

    public function beginTarget(): void;

    public function endCommand(): void;

    public function endTarget(): void;
}
