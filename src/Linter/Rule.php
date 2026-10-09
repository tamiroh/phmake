<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Linter;

use Tamiroh\Phmake\Parser\Ast\Node;

/**
 * Syntax-only rules must not infer runtime variable usage from lexical references.
 */
interface Rule
{
    /**
     * @return list<Diagnostic>
     */
    public function inspect(Node $node): array;
}
