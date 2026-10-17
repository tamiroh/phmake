<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Files;

use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\SpecialTargets;

/**
 * Explicit intermediate declarations take priority over inherited pattern attributes.
 *
 * @internal
 */
final readonly class IntermediateFiles
{
    /**
     * @throws MakefileErrorException
     */
    public function __construct(
        private SpecialTargets $special,
    ) {
        if ($special->hasEmptyRule('.SECONDARY') && $special->hasEmptyRule('.NOTINTERMEDIATE')) {
            throw new MakefileErrorException('.NOTINTERMEDIATE and .SECONDARY are mutually exclusive');
        }
        foreach ($special->names('.NOTINTERMEDIATE') as $name) {
            foreach (['.INTERMEDIATE', '.SECONDARY'] as $kind) {
                if ($special->contains($kind, $name)) {
                    throw new MakefileErrorException("{$name} cannot be both .NOTINTERMEDIATE and {$kind}");
                }
            }
        }
    }

    public function intermediate(string $name, bool $inferred, bool $notIntermediate = false): bool
    {
        if ($this->special->contains('.INTERMEDIATE', $name) || $this->special->contains('.SECONDARY', $name)) {
            return true;
        }
        if (
            $notIntermediate
            || $this->special->hasEmptyRule('.NOTINTERMEDIATE')
            || $this->special->contains('.NOTINTERMEDIATE', $name)
        ) {
            return false;
        }
        return $inferred || $this->special->hasEmptyRule('.SECONDARY');
    }

    public function preserve(string $name): bool
    {
        return $this->special->hasEmptyRule('.SECONDARY') || $this->special->contains('.SECONDARY', $name);
    }
}
