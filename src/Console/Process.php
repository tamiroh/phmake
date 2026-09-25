<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use function fwrite;
use function str_ends_with;
use function trim;

use const PHP_EOL;
use const STDERR;

final class Process
{
    private const string MESSAGE_PREMIX = 'phmake: ';

    public static function stopWithCommandFailure(string $target, int $exitCode): never
    {
        fwrite(STDERR, self::MESSAGE_PREMIX . "*** [$target] Error $exitCode" . PHP_EOL);

        exit(2);
    }

    public static function stopWithError(string $message, string $source = 'phmake'): never
    {
        $trimmedMessage = trim($message);
        $formattedMessage = $trimmedMessage . (str_ends_with($trimmedMessage, '.') ? '' : '.');

        fwrite(STDERR, "$source: *** $formattedMessage  Stop." . PHP_EOL);

        exit(2);
    }
}
