<?php

namespace Tamiroh\Phmake\Console;

final class Process
{
    private const string MESSAGE_PREMIX = 'phmake: ';

    public static function error(string $message): never
    {
        $trimmedMessage = trim($message);
        $formattedMessage = $trimmedMessage . (str_ends_with($trimmedMessage, '.') ? '' : '.');

        echo self::MESSAGE_PREMIX . "*** $formattedMessage  Stop." . PHP_EOL;
        exit(1);
    }
}