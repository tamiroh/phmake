<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Filesystem;

use Tamiroh\Phmake\Makefile\Rule\ArchiveMember;

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
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return [];
        }
        try {
            if (fread($stream, 8) !== "!<arch>\n") {
                return [];
            }
            $members = [];
            $names = '';
            while (!feof($stream)) {
                $offset = ftell($stream);
                $header = fread($stream, 60);
                if ($offset === false || $header === false || strlen($header) !== 60 || substr($header, 58) !== "`\n") {
                    break;
                }
                $size = (int) trim(substr($header, 48, 10));
                if ($size < 0) {
                    break;
                }
                $name = rtrim(substr($header, 0, 16));
                if ($name === '//') {
                    $names = $size === 0 ? '' : fread($stream, $size);
                    if ($names === false) {
                        break;
                    }
                } elseif (str_starts_with($name, '#1/')) {
                    $length = (int) substr($name, 3);
                    if ($length <= 0 || $length > $size) {
                        break;
                    }
                    $name = fread($stream, $length);
                    if ($name === false) {
                        break;
                    }
                    $name = rtrim($name, "\0");
                } elseif (preg_match('~^/[0-9]+$~D', $name) === 1) {
                    $name = explode("/\n", substr($names, (int) substr($name, 1)), 2)[0];
                } else {
                    $name = rtrim($name, '/');
                }
                if ($name !== '' && $name !== '//') {
                    $members[$name] = [
                        'name' => $name,
                        'time' => (int) trim(substr($header, 16, 12)),
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
        $fileTime = FileTimes::modified($member->member, false);
        return $time !== null && $fileTime !== null && $fileTime > $time ? null : $time;
    }

    public static function touch(ArchiveMember $member): bool
    {
        $entry = self::members($member->archive)[basename($member->member)] ?? null;
        if ($entry === null || ($stream = @fopen($member->archive, 'r+b')) === false) {
            return false;
        }
        try {
            return fseek($stream, $entry['offset'] + 16) === 0 && fwrite($stream, str_pad((string) time(), 12)) === 12;
        } finally {
            fclose($stream);
        }
    }
}
