<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Execution\Internal;

use Tamiroh\Phmake\Engine\MakefileErrorException;

/**
 * A missing input whose phony or unconditional double-colon rule cannot be remade.
 */
final class UnremadeMakefileException extends MakefileErrorException {}
