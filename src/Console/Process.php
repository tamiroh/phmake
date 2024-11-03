<?php

namespace Tamiroh\Phmake\Console;

final class Process
{
    public static function stop(string $message): never
    {
        echo Message::getStopMessage($message) . PHP_EOL;
        exit(1);
    }
}