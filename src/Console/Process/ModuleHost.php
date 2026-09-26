<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Closure;
use Override;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Makefile\IO\ModuleHost as ModuleHostInterface;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * Transport native plugin calls without requiring PHP's FFI extension.
 */
final class ModuleHost implements ModuleHostInterface
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct(
        private readonly string $executable,
        private readonly Output $output,
    ) {}

    /**
     * @throws MakefileErrorException
     */
    public static function capabilities(Output $output): string
    {
        $path = self::executable();
        return (
            $path === null
                ? ''
                : new self($path, $output)->request('P', [], static fn(string $kind, array $values): ?string => null)
        );
    }

    public static function executable(): ?string
    {
        $path = getenv('PHMAKE_MODULE_HOST');
        if ($path === false || $path === '') {
            $path = '/usr/local/libexec/phmake-module-host';
        }
        return is_executable($path) ? $path : null;
    }

    /**
     * @param list<string> $arguments
     * @param Closure(string, list<string>): ?string $callback
     *
     * @throws MakefileErrorException
     */
    #[Override]
    public function request(string $operation, array $arguments, Closure $callback): string
    {
        if ($this->process === null) {
            $pipes = [];
            $process = @proc_open(
                [$this->executable],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            if ($process === false) {
                throw new MakefileErrorException('Cannot start native module host');
            }
            $this->process = $process;
            $this->pipes = $pipes;
        }
        $this->send($operation, $arguments);
        while (true) {
            $kind = $this->read(1);
            $count = $this->number();
            if ($count > 65536) {
                throw new MakefileErrorException('Invalid native module response');
            }
            $values = [];
            for ($index = 0; $index < $count; $index++) {
                $values[] = $this->read($this->number());
            }
            if ($kind === 'R') {
                return $values[0] ?? '';
            }
            if ($kind === 'E') {
                throw new MakefileErrorException($values[0] ?? 'Native module failed');
            }
            if ($kind === 'O' || $kind === 'S') {
                $this->output->buffer->write($values[0] ?? '', $kind === 'S');
                continue;
            }
            $response = $callback($kind, $values);
            if ($response !== null) {
                $this->send('H', [$response]);
            }
        }
    }

    /**
     * @throws MakefileErrorException
     */
    private function number(): int
    {
        $bytes = $this->read(4);
        return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
    }

    /**
     * @throws MakefileErrorException
     */
    private function read(int $length): string
    {
        if ($length > (64 * 1024 * 1024) || !isset($this->pipes[1])) {
            throw new MakefileErrorException('Invalid native module response');
        }
        $value = '';
        while (($remaining = $length - strlen($value)) > 0) {
            $part = fread($this->pipes[1], $remaining);
            if ($part === false || $part === '') {
                throw new MakefileErrorException('Native module host closed its response stream');
            }
            $value .= $part;
        }
        return $value;
    }

    /**
     * @param list<string> $values
     *
     * @throws MakefileErrorException
     */
    private function send(string $kind, array $values): void
    {
        if (!isset($this->pipes[0])) {
            throw new MakefileErrorException('Native module host is not running');
        }
        $packet = $kind . pack('N', count($values));
        foreach ($values as $value) {
            $packet .= pack('N', strlen($value)) . $value;
        }
        while ($packet !== '') {
            $written = @fwrite($this->pipes[0], $packet);
            if ($written === false || $written === 0) {
                throw new MakefileErrorException('Cannot write to native module host');
            }
            $packet = substr($packet, $written);
        }
    }

    public function __destruct()
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
