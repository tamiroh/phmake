<?php

namespace Tamiroh\Phmake;

final class Message
{
    private const string PREMIX = 'phmake: ';

    public static function getStopMessage(string $message): string
    {
        $trimmedMessage = trim($message);
        $formattedMessage = $trimmedMessage . (str_ends_with($trimmedMessage, '.') ? '' : '.');

        return self::PREMIX . "*** $formattedMessage  Stop.";
    }
}