<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Linter;

use Tamiroh\Phmake\Parser\Ast\MakefileNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\Walker;

final readonly class Linter
{
    /**
     * @param list<Rule> $rules
     */
    public function __construct(
        private array $rules = [],
    ) {}

    /**
     * @return list<Diagnostic>
     */
    public function lint(MakefileNode $makefile): array
    {
        $diagnostics = [];
        new Walker()->walk($makefile, function (Node $node) use (&$diagnostics): void {
            foreach ($this->rules as $rule) {
                $diagnostics = [...$diagnostics, ...$rule->inspect($node)];
            }
        });
        return $diagnostics;
    }
}
