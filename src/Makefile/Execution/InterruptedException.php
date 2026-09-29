<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Execution;

use Tamiroh\Phmake\Makefile\MakefileErrorException;

/**
 * A build interruption is fatal even under -k or an ignored recipe error.
 */
final class InterruptedException extends MakefileErrorException
{
    /**
     * @param string $signal the signal's description, as reported for interrupted targets
     */
    public function __construct(
        public readonly string $signal,
    ) {
        parent::__construct('Build interrupted');
    }
}
