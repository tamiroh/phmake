<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\Evaluation\EvaluationContext;
use Tamiroh\Phmake\Makefile\Makefile;

/**
 * Definitions and the live evaluation context produced by one read.
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
