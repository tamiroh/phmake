<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Rule;

/**
 * An archive member has its own timestamp but uses the archive as its recipe target.
 */
final readonly class ArchiveMember
{
    public function __construct(
        public string $archive,
        public string $member,
    ) {}

    public static function expand(string $text): string
    {
        return preg_replace_callback(
            '/([^\s()]+)\(([^()]*)\)(?=\s|$)/',
            static function (array $matches): string {
                return implode(' ', array_map(
                    static fn(string $member): string => $matches[1] . '(' . $member . ')',
                    DependencySyntax::words($matches[2]),
                ));
            },
            $text,
        ) ?? $text;
    }

    public static function parse(string $name): ?self
    {
        if (preg_match('/^(.+)\(([^()]+)\)$/D', $name, $matches) !== 1) {
            return null;
        }
        return new self($matches[1], $matches[2]);
    }

    public static function prerequisite(string $name): string
    {
        return self::parse($name)->member ?? $name;
    }
}
