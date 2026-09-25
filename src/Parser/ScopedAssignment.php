<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use Tamiroh\Phmake\Makefile\Assignment;
use Tamiroh\Phmake\Makefile\DependencySyntax;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Output;
use Tamiroh\Phmake\Makefile\TargetVariables;
use Tamiroh\Phmake\Makefile\VariableExpander;

use function ltrim;
use function preg_match;
use function substr;

final readonly class ScopedAssignment
{
    private function __construct(
        public Assignment $assignment,
        public string $origin,
        public bool $private,
        public ?bool $export,
    ) {}

    public static function parse(string $text): ?self
    {
        $text = ltrim($text);
        $origin = 'file';
        $private = false;
        $export = null;
        while (preg_match('/^(override|private|export|unexport)\s+(.*)$/s', $text, $match) === 1) {
            if (Assignment::parse($text) !== null) {
                break;
            }
            if ($match[1] === 'override') {
                $origin = 'override';
            } elseif ($match[1] === 'private') {
                $private = true;
            } else {
                $export = $match[1] === 'export';
            }
            $text = ltrim($match[2]);
        }
        $assignment = Assignment::parse($text);
        if ($assignment === null) {
            return null;
        }
        return new self($assignment, $origin, $private, $export);
    }

    /** @throws MakefileErrorException */
    public static function read(
        string $line,
        TargetVariables $variables,
        VariableExpander $expander,
        ?Output $output,
    ): bool {
        $colon = DependencySyntax::delimiter($line, ':');
        if ($colon === null) {
            return false;
        }
        $declaration = self::parse(substr($line, $colon + 1));
        if ($declaration === null) {
            return false;
        }
        foreach (DependencySyntax::words($expander->expand(substr($line, 0, $colon))) as $target) {
            $variables->define(
                $target,
                $declaration->assignment,
                $expander,
                $declaration->origin,
                $declaration->private,
                $declaration->export,
                $output,
            );
        }
        return true;
    }
}
