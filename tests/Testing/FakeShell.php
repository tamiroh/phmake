<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use Closure;
use Override;
use Tamiroh\Phmake\Engine\IO\Shell;

/**
 * Records shell calls without starting processes.
 */
final class FakeShell implements Shell
{
    /** @var list<string> */
    public private(set) array $commands = [];

    /** @var list<string> */
    public private(set) array $captures = [];

    /**
     * @param Closure(string): int|null $execute
     */
    public function __construct(
        private readonly int $exitCode = 0,
        private readonly ?Closure $execute = null,
    ) {}

    /**
     * @throws void
     */
    #[Override]
    public function capture(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
    ): array {
        $this->captures[] = $command;
        return ['output' => '', 'status' => $this->exitCode];
    }

    #[Override]
    public function exec(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
        bool $ignoreErrors = false,
        bool $recursive = false,
    ): int {
        $this->commands[] = $command;
        return $this->execute === null ? $this->exitCode : ($this->execute)($command);
    }
}
