<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console;

use Closure;
use Override;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tamiroh\Phmake\Makefile\IO\Shell as ShellInterface;
use Tamiroh\Phmake\Makefile\IO\ShellResult;

use function escapeshellarg;
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
        $process = $this->process($command, $environment, $shell, $flags);
        $status = $this->run($process, function (string $type, string $buffer): void {
            if ($type === SymfonyProcess::ERR) {
                if ($this->output !== null) {
                    $this->output->buffer->write($buffer, true);
                } else {
                    fwrite(STDERR, $buffer);
                }
            }
        });
        return new ShellResult($process->getOutput(), $status);
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
        return RecipeProcess::run(
            'exec ' . $shell . ' ' . $flags . ' ' . escapeshellarg($command),
            [...$this->environment, ...$environment],
            ($this->output?->buffer->descriptors() ?? []) + ($recursive ? $descriptors : []),
        );
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function process(string $command, array $environment, string $shell, string $flags): SymfonyProcess
    {
        $process = SymfonyProcess::fromShellCommandline(
            'exec ' . $shell . ' ' . $flags . ' ' . escapeshellarg($command),
            env: [...$this->environment, ...$environment],
        );
        $process->setTimeout(null);
        return $process;
    }

    /**
     * @param Closure(string, string): void $callback
     */
    private function run(SymfonyProcess $process, Closure $callback): int
    {
        try {
            return $process->run($callback);
        } catch (ProcessSignaledException $error) {
            return 128 + $error->getSignal();
        }
    }
}
