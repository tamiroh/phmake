<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Assignment;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;

use function array_values;

final class AssignmentTest extends TestCase
{
    /** @throws MakefileErrorException */
    #[Test]
    public function appendingRetainsTheExistingExpansionTiming(): void
    {
        $variables = ['X' => new Variable('X', 'before')];
        Assignment::parse('S := $(X)')?->apply($variables, 'file');
        Assignment::parse('R = $(X)')?->apply($variables, 'file');
        Assignment::parse('S += $(X)')?->apply($variables, 'override');
        Assignment::parse('R += $(X)')?->apply($variables, 'override');
        Assignment::parse('X = after')?->apply($variables, 'file');
        Assignment::parse('S ?= $(error must not expand)')?->apply($variables, 'override');
        self::assertSame(
            'before before|after after',
            new VariableExpander(array_values($variables))->expand('$(S)|$(R)'),
        );
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function strongerDefinitionsPreventExpansionOfIgnoredAssignments(): void
    {
        $variables = ['X' => new Variable('X', 'environment', origin: 'environment override')];
        Assignment::parse('X := $(error must not expand)')?->apply($variables, 'file');
        self::assertSame('environment', $variables['X']->expression);
        Assignment::parse('X = command')?->apply($variables, 'command line');
        Assignment::parse('X += extra')?->apply($variables, 'override');
        Assignment::parse('X = ignored')?->apply($variables, 'command line');
        self::assertSame('command extra', $variables['X']->expression);
        self::assertSame('override', $variables['X']->origin);
    }
}
