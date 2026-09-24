<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Functions;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;

final class DiagnosticsTest extends TestCase
{
    /** @throws MakefileErrorException */
    #[Test]
    public function directErrorCallsDoNotRequireASourceLocation(): void
    {
        $this->expectException(MakefileErrorException::class);
        Functions::error('stopped');
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function errorStopsExpansionBeforeLaterSideEffects(): void
    {
        $output = new FakeOutput();
        try {
            new VariableExpander([], $output, source: 'example.mk:3')->expand('$(error stopped)$(info must-not-run)');
            self::fail('The error function must stop expansion.');
        } catch (MakefileErrorException $error) {
            self::assertSame('stopped', $error->getMessage());
            self::assertSame('example.mk:3', $error->source);
            self::assertSame([], $output->writes);
        }
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function warningsReturnAnEmptyValueAndPreserveTheExpansionLocation(): void
    {
        $output = new FakeOutput();
        self::assertSame(
            'done',
            new VariableExpander(
                [new Variable('message', '$(foreach x,a,$(call warning,$x))')],
                $output,
                source: 'example.mk:7',
            )->expand('$(message)done'),
        );
        self::assertSame(['a'], $output->warnings);
        self::assertSame(['example.mk:7'], $output->warningSources);
    }
}
