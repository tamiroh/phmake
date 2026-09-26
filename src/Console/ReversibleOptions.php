<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

/**
 * Preserve explicit negative switches and the precedence of their sources.
 */
final class ReversibleOptions
{
    /** @var array<string, array{bool, int}> */
    private array $settings = [];

    public function set(string $name, bool $value, string $origin): bool
    {
        $priority = match ($origin) {
            'command line' => 2,
            'environment' => 1,
            default => 0,
        };
        if ($priority >= ($this->settings[$name][1] ?? -1)) {
            $this->settings[$name] = [$value, $priority];
        }
        return $this->settings[$name][0];
    }

    public function value(string $name): ?bool
    {
        return $this->settings[$name][0] ?? null;
    }
}
