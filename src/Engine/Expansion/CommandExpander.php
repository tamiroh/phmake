<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Engine\Expansion;

use Tamiroh\Phmake\Engine\MakefileErrorException;
use Tamiroh\Phmake\Engine\Rule\Command;

final class CommandExpander
{
    /**
     * @throws MakefileErrorException
     */
    public static function expand(Command $command, VariableExpander $expander): ExpandedCommand
    {
        return new ExpandedCommand(
            $expander->atSource($command->source)->expand($command->expression),
            $command->prefix(),
            $command->isRecursive(),
            $command->source,
        );
    }
}
