<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation\LoadedObject;

use Override;
use Tamiroh\Phmake\Makefile\Evaluation\Functions;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\LoadedObjectApi;
use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * Serve a loaded object's requests in the expansion context that called it, including nested eval.
 *
 * @internal
 */
final readonly class ExpansionApi implements LoadedObjectApi
{
    public function __construct(
        private LoadedObjects $loadedObjects,
        private LoadedObject $object,
        private VariableExpander $expander,
    ) {}

    /**
     * @throws MakefileErrorException
     */
    #[Override]
    public function define(string $name, int $minimum, int $maximum, bool $expand): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1) {
            throw new MakefileErrorException("Invalid loaded function name '{$name}'");
        }
        $this->loadedObjects->functions[$name] = new LoadedFunction($this->object, $minimum, $maximum, $expand);
    }

    /**
     * @throws MakefileErrorException
     */
    #[Override]
    public function eval(string $text, ?string $file, int $line): void
    {
        Functions::eval(
            $text,
            $this->expander->context->evaluate,
            $file === null ? $this->expander : $this->expander->atSource("{$file}:{$line}"),
        );
    }

    /**
     * @throws MakefileErrorException
     */
    #[Override]
    public function expand(string $text): string
    {
        return $this->expander->expand($text);
    }
}
