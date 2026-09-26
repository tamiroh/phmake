<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Override;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Makefile\DebugTrace;
use Tamiroh\Phmake\Makefile\IO\Shell as ShellInterface;
use Tamiroh\Phmake\Makefile\IO\ShellResult;
use Tamiroh\Phmake\Makefile\ReportingOptions;

use function explode;
use function fwrite;
use function preg_replace;

use const STDERR;

final class Shell implements ShellInterface
{
    /**
     * @param array<string, string|false> $environment
     */
    public function __construct(
        private readonly array $environment = [],
        private readonly ?Jobserver $jobserver = null,
        private readonly ?Output $output = null,
        private readonly ?ReportingOptions $reporting = null,
    ) {}

    /**
     * @param array<string, string|false> $environment
     *
     * @return array<string, string|false>
     */
    private static function withoutPipeJobserver(array $environment): array
    {
        if (isset($environment['MAKEFLAGS']) && $environment['MAKEFLAGS'] !== false) {
            $parts = explode(' -- ', $environment['MAKEFLAGS'], 2);
            $environment['MAKEFLAGS'] =
                (
                    preg_replace('/(^| )--jobserver-auth=[0-9]+,[0-9]+(?= |$)/', '$1--jobserver-auth=-2,-2', $parts[0])
                    ?? $parts[0]
                )
                . (isset($parts[1]) ? ' -- ' . $parts[1] : '');
        }
        return $environment;
    }

    /**
     * @param array<string, string|false> $environment
     */
    #[Override]
    public function capture(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
    ): ShellResult {
        $environment = self::withoutPipeJobserver($environment);
        $environment = [...$this->environment, ...$environment];
        $invocation = CommandInvocation::parse($command, $shell, $flags);
        if ($this->failed($invocation, $environment)) {
            return new ShellResult('', 127);
        }
        return CapturedProcess::run($invocation->launch(), $environment, function (string $buffer): void {
            if ($this->output !== null) {
                $this->output->buffer->write($buffer, true);
            } else {
                fwrite(STDERR, $buffer);
            }
        });
    }

    /**
     * @param array<string, string|false> $environment
     */
    #[Override]
    public function exec(
        string $command,
        array $environment = [],
        string $shell = '/bin/sh',
        string $flags = '-c',
        bool $ignoreErrors = false,
        bool $recursive = false,
    ): int {
        $descriptors = $this->jobserver?->descriptors() ?? [];
        if (!$recursive) {
            $environment = self::withoutPipeJobserver($environment);
        }
        $environment = [...$this->environment, ...$environment];
        $invocation = CommandInvocation::parse($command, $shell, $flags);
        if ($this->failed($invocation, $environment)) {
            return 127;
        }
        return RecipeProcess::run(
            $invocation->launch(),
            $environment,
            ($this->output?->buffer->descriptors() ?? []) + ($recursive ? $descriptors : []),
            function (int $pid, ?int $exitCode): void {
                if ($this->reporting !== null) {
                    DebugTrace::write(
                        $this->reporting,
                        $this->output,
                        'j',
                        $exitCode === null
                            ? "Putting child PID $pid on the chain."
                            : 'Reaping ' . ($exitCode === 0 ? 'winning' : 'losing') . " child PID $pid",
                    );
                }
            },
        );
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function failed(CommandInvocation $invocation, array $environment): bool
    {
        $error = $invocation->error($environment);
        if ($error === null) {
            return false;
        }
        if ($this->output !== null) {
            $this->output->writeWarning($error);
        } else {
            fwrite(STDERR, 'phmake: ' . $error . "\n");
        }
        return true;
    }
}
