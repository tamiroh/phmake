<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Filesystem\Internal;

use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\ArchiveMember;

use function basename;
use function error_get_last;
use function explode;
use function fclose;
use function feof;
use function file_exists;
use function fnmatch;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function fwrite;
use function intdiv;
use function preg_match;
use function preg_replace;
use function rtrim;
use function sort;
use function str_pad;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function trim;
use function ucfirst;

/**
 * Read Unix ar member headers, including GNU and BSD extended member names.
 */
final class Archive
{
    /**
     * @return list<string>
     */
    public static function matching(ArchiveMember $pattern): array
    {
        $result = [];
        foreach (self::members($pattern->archive) as $member) {
            if (fnmatch($pattern->member, $member['name'])) {
                $result[] = $pattern->archive . '(' . $member['name'] . ')';
            }
        }
        sort($result);
        return $result;
    }

    /**
     * @return array<string, array{name: string, time: int, offset: int}>
     */
    public static function members(string $path): array
    {
        $stream = @fopen($path, mode: 'rb');
        if ($stream === false) {
            return [];
        }
        try {
            if (fread($stream, length: 8) !== "!<arch>\n") {
                return [];
            }
            $members = [];
            $names = '';
            while (!feof($stream)) {
                $offset = ftell($stream);
                $header = fread($stream, length: 60);
                if (
                    $offset === false
                    || $header === false
                    || strlen($header) !== 60
                    || substr($header, offset: 58) !== "`\n"
                ) {
                    break;
                }
                $size = (int) trim(substr($header, offset: 48, length: 10));
                if ($size < 0) {
                    break;
                }
                $name = rtrim(substr($header, offset: 0, length: 16));
                if ($name === '//') {
                    $names = $size === 0 ? '' : fread($stream, $size);
                    if ($names === false) {
                        break;
                    }
                } elseif (str_starts_with($name, '#1/')) {
                    $length = (int) substr($name, offset: 3);
                    if ($length <= 0 || $length > $size) {
                        break;
                    }
                    $name = fread($stream, $length);
                    if ($name === false) {
                        break;
                    }
                    $name = rtrim($name, characters: "\0");
                } elseif (preg_match('~^/[0-9]+$~D', $name) === 1) {
                    $name = explode("/\n", substr($names, (int) substr($name, offset: 1)), limit: 2)[0];
                } else {
                    $name = rtrim($name, characters: '/');
                }
                if ($name !== '' && $name !== '//') {
                    $members[$name] = [
                        'name' => $name,
                        'time' => (int) trim(substr($header, offset: 16, length: 12)),
                        'offset' => $offset,
                    ];
                }
                if (fseek($stream, $offset + 60 + $size + ($size % 2)) !== 0) {
                    break;
                }
            }
            return $members;
        } finally {
            fclose($stream);
        }
    }

    public static function modified(ArchiveMember $member): ?int
    {
        $time = self::members($member->archive)[basename($member->member)]['time'] ?? null;
        $fileTime = FileTimes::modified($member->member, links: false);
        return $time !== null && $fileTime !== null && intdiv($fileTime, num2: 1_000_000_000) > $time ? null : $time;
    }

    /**
     * @throws MakefileErrorException
     */
    public static function touch(ArchiveMember $member): void
    {
        $stream = @fopen($member->archive, mode: 'r+b');
        if ($stream === false) {
            $reason = ucfirst(
                preg_replace(
                    '/^.*Failed to open stream: /',
                    replacement: '',
                    subject: error_get_last()['message'] ?? 'I/O error',
                ) ?? 'I/O error',
            );
            throw new MakefileErrorException(
                file_exists($member->archive)
                    ? "touch: {$member->archive}: {$reason}"
                    : "touch: Archive '{$member->archive}' does not exist",
                contextual: false,
            );
        }
        try {
            if (fread($stream, length: 8) !== "!<arch>\n") {
                throw new MakefileErrorException(
                    "touch: '{$member->archive}' is not a valid archive",
                    contextual: false,
                );
            }
            $entry = self::members($member->archive)[basename($member->member)] ?? null;
            if ($entry === null) {
                throw new MakefileErrorException(
                    "touch: Member '{$member->member}' does not exist in '{$member->archive}'",
                    contextual: false,
                );
            }
            if (
                fseek($stream, $entry['offset'] + 16) !== 0
                || fwrite($stream, str_pad((string) time(), length: 12)) !== 12
            ) {
                throw new MakefileErrorException("touch: {$member->archive}: I/O error", contextual: false);
            }
        } finally {
            fclose($stream);
        }
    }
}
