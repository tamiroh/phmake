<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Rule;

use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

final readonly class Pattern
{
    private string $prefix;

    private ?string $suffix;

    public function __construct(string $expression)
    {
        [$this->prefix, $this->suffix] = self::parts($expression);
    }

    /**
     * @return array{string, ?string}
     */
    private static function parts(string $expression): array
    {
        $prefix = '';
        $offset = 0;
        while (($percent = strpos($expression, '%', $offset)) !== false) {
            $slashStart = $percent;
            while ($slashStart > $offset && $expression[$slashStart - 1] === '\\') {
                $slashStart--;
            }
            $slashes = substr($expression, $slashStart, $percent - $slashStart);
            $escaped = (strlen($slashes) % 2) !== 0;
            $prefix .=
                substr($expression, $offset, $slashStart - $offset)
                . str_replace('\\\\', '\\', $escaped ? substr($slashes, 0, -1) : $slashes);
            if (!$escaped) {
                return [$prefix, substr($expression, $percent + 1)];
            }
            $prefix .= '%';
            $offset = $percent + 1;
        }
        return [$prefix . substr($expression, $offset), null];
    }

    /**
     * @pure
     */
    public function hasWildcard(): bool
    {
        return $this->suffix !== null;
    }

    /**
     * @pure
     */
    public function match(string $name): ?string
    {
        if ($this->suffix === null) {
            return $name === $this->prefix ? '' : null;
        }
        if (
            !str_starts_with($name, $this->prefix)
            || !str_ends_with($name, $this->suffix)
            || strlen($name) < (strlen($this->prefix) + strlen($this->suffix))
        ) {
            return null;
        }
        return substr($name, strlen($this->prefix), strlen($name) - strlen($this->prefix) - strlen($this->suffix));
    }

    /**
     * @pure
     */
    public function substitute(string $stem): string
    {
        return $this->suffix === null ? $this->prefix : $this->prefix . $stem . $this->suffix;
    }
}
