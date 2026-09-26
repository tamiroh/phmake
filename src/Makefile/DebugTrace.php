<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Tamiroh\Phmake\Makefile\IO\Output;

use function max;
use function str_repeat;

/**
 * Emit diagnostic events from the actual traversal, independently of recipe silence.
 */
final class DebugTrace
{
    public static function write(
        ReportingOptions $options,
        ?Output $output,
        string $level,
        string $message,
        int $depth = 0,
    ): void {
        if ($options->enabled($level)) {
            $output?->write(str_repeat(' ', max(0, $depth)) . $message . "\n");
        }
    }
}
