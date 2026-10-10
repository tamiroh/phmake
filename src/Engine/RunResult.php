<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine;

/**
 * Outcome of one execution pass.
 */
enum RunResult
{
    case Succeeded;
    case OutOfDate;
    case Failed;
    case NeedsReevaluation;
}
