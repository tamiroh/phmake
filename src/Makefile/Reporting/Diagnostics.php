<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Reporting;

use Tamiroh\Phmake\Makefile\Execution\Recipe\CommandFailedException;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

use function str_ends_with;
use function trim;

/**
 * Report a failure once, through the same output channel as its build phase.
 *
 * @internal
 */
final class Diagnostics
{
    public static function report(
        MakefileErrorException|CommandFailedException $error,
        Output $output,
        bool $stop = true,
    ): void {
        if ($error->reported) {
            return;
        }
        $message = trim($error->getMessage());
        if ($error instanceof MakefileErrorException) {
            $message .= (str_ends_with($message, '.') ? '' : '.') . ($stop ? '  Stop.' : '');
        }
        $output->writeWarning('*** ' . $message, $error instanceof MakefileErrorException ? $error->source : null);
        $error->reported = true;
    }
}
