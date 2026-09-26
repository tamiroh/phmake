<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use function error_get_last;
use function fwrite;
use function preg_replace;
use function stream_copy_to_stream;
use function substr;

/**
 * Report failed writes instead of returning success after losing output.
 */
final class StreamOutput
{
    /**
     * @param resource $from
     * @param resource $to
     */
    public static function copy(mixed $from, mixed $to): void
    {
        if (@stream_copy_to_stream($from, $to) === false) {
            throw self::failure();
        }
    }

    /**
     * @param resource $stream
     */
    public static function write(mixed $stream, string $text): void
    {
        while ($text !== '') {
            $written = @fwrite($stream, $text);
            if ($written === false || $written === 0) {
                throw self::failure();
            }
            $text = substr($text, $written);
        }
    }

    private static function failure(): OutputWriteException
    {
        return new OutputWriteException(
            'write error: '
            . (preg_replace('/^.*errno=[0-9]+ /', '', error_get_last()['message'] ?? 'I/O error') ?? 'I/O error'),
        );
    }
}
