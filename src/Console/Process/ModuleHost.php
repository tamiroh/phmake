<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Console\Process;

use Closure;
use Tamiroh\Phmake\Console\Output\Output;
use Tamiroh\Phmake\Console\Process\Internal\ModuleChannel;
use Tamiroh\Phmake\Makefile\IO\LoadedObjectApi;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * Transport native plugin calls without requiring PHP's FFI extension.
 */
final readonly class ModuleHost
{
    private ModuleChannel $channel;

    public function __construct(string $executable, Output $output)
    {
        $this->channel = new ModuleChannel($executable, $output);
    }

    /**
     * @throws MakefileErrorException
     */
    public static function capabilities(Output $output): string
    {
        $path = self::executable();
        return (
            $path === null
                ? ''
                : new ModuleChannel($path, $output)->request(
                    'P',
                    [],
                    static fn(string $kind, array $values): ?string => null,
                )
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
     * Decode a loaded object's request; a non-null result is sent back as its reply.
     *
     * @param list<string> $values
     *
     * @throws MakefileErrorException
     */
    private static function answer(LoadedObjectApi $api, string $kind, array $values): ?string
    {
        if ($kind === 'F' && count($values) === 4) {
            // Bit 0 is GMK_FUNC_NOEXPAND.
            $api->define($values[0], (int) $values[1], (int) $values[2], ((int) $values[3] & 1) === 0);
            return null;
        }
        if ($kind === 'V' && count($values) === 1) {
            return $api->expand($values[0]);
        }
        if ($kind === 'A' && count($values) === 3) {
            $api->eval($values[0], $values[1] === '' ? null : $values[1], (int) $values[2]);
            return '';
        }
        throw new MakefileErrorException('Invalid native module callback');
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MakefileErrorException
     */
    public function call(string $name, array $arguments, LoadedObjectApi $api): string
    {
        return $this->request('C', [$name, ...$arguments], $api);
    }

    /**
     * @param Closure(string): string $expand
     * @param Closure(string): void $eval
     *
     * @throws MakefileErrorException
     */
    public function guile(string $expression, Closure $expand, Closure $eval): string
    {
        return $this->channel->request(
            'G',
            [$expression],
            /** @throws MakefileErrorException */
            static function (string $kind, array $values) use ($expand, $eval): string {
                if ($kind === 'V' && count($values) === 1) {
                    return $expand($values[0]);
                }
                if ($kind === 'A' && count($values) === 3) {
                    $eval($values[0]);
                    return '';
                }
                throw new MakefileErrorException('Invalid Guile callback');
            },
        );
    }

    /**
     * @throws MakefileErrorException
     */
    public function load(string $path, string $setup, ?string $file, int $line, LoadedObjectApi $api): int
    {
        return (int) $this->request('L', [$path, $setup, $file ?? '', (string) $line], $api);
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MakefileErrorException
     */
    private function request(string $operation, array $arguments, LoadedObjectApi $api): string
    {
        return $this->channel->request(
            $operation,
            $arguments,
            /** @throws MakefileErrorException */
            static fn(string $kind, array $values): ?string => self::answer($api, $kind, $values),
        );
    }
}
