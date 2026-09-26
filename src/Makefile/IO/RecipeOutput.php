<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\IO;

interface RecipeOutput extends Output
{
    public function beginCommand(bool $recursive): void;

    public function beginTarget(): void;

    public function endCommand(): void;

    public function endTarget(): void;
}
