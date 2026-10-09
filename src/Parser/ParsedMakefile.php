<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\Expansion\EvaluationContext;
use Tamiroh\Phmake\Makefile\Makefile;

/**
 * Execution definitions and the live context produced by Evaluator.
 * This is an evaluation result, not the public syntax AST.
 * The context continues through makefile remaking and goal execution;
 * a restart reads both anew.
 */
final readonly class ParsedMakefile
{
    public function __construct(
        public Makefile $makefile,
        public EvaluationContext $context,
    ) {}
}
