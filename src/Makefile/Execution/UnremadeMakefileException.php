<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * A missing input whose phony or unconditional double-colon rule cannot be remade.
 */
final class UnremadeMakefileException extends MakefileErrorException {}
