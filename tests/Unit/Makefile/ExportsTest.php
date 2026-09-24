<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Makefile;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Makefile\Command;
use Tamiroh\Phmake\Makefile\CommandFailedException;
use Tamiroh\Phmake\Makefile\Exports;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Target;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Tests\Testing\FakeFilesystem;
use Tamiroh\Phmake\Tests\Testing\FakeOutput;
use Tamiroh\Phmake\Tests\Testing\FakeShell;

final class ExportsTest extends TestCase
{
    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    #[Test]
    public function doesNotExpandExportsForAnUpToDateTarget(): void
    {
        $exports = new Exports();
        $exports->set(['CYCLE'], true);
        self::assertFalse(new Target('output', [], [new Command('echo unused')], false)->run(
            new FakeShell(),
            new FakeFilesystem(['output' => ['modifiedAt' => new DateTimeImmutable('2026-01-01')]]),
            new FakeOutput(),
            [new Variable('CYCLE', '$(CYCLE)')],
            exports: $exports,
        ));
    }

    /**
     * @throws MakefileErrorException
     * @throws CommandFailedException
     */
    #[Test]
    public function expandsExportsForEachCommandWithAutomaticVariables(): void
    {
        $exports = new Exports();
        $exports->set(['EXPORTED'], true);
        $shell = new FakeShell();
        $output = new FakeOutput();
        new Target('output', [], [new Command('@echo first'), new Command('@echo second')], true)->run(
            $shell,
            new FakeFilesystem([]),
            $output,
            [new Variable('EXPORTED', '$(info export)$@')],
            exports: $exports,
        );
        self::assertSame([['EXPORTED' => 'output'], ['EXPORTED' => 'output']], $shell->environments);
        self::assertSame(["export\n", "export\n"], $output->writes);
    }

    /** @throws MakefileErrorException */
    #[Test]
    public function preservesInheritedDollarsAndSkipsUnexportedCycles(): void
    {
        $exports = new Exports(['INHERITED', 'CYCLE']);
        $exports->set(['CYCLE'], false);
        self::assertSame(
            ['CYCLE' => false, 'INHERITED' => '$(literal)'],
            $exports->environment([
                new Variable('INHERITED', '$(literal)', origin: 'environment'),
                new Variable('CYCLE', '$(CYCLE)'),
            ], new FakeOutput()),
        );
    }
}
