<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Linter\Rules;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Linter\Diagnostic;
use Tamiroh\Phmake\Linter\Linter;
use Tamiroh\Phmake\Linter\Rules\RecursiveMake;
use Tamiroh\Phmake\Parser\MakefileParser;

use function array_map;

final class RecursiveMakeTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function lint(string $source): array
    {
        return array_map(
            static fn(Diagnostic $diagnostic): string => $diagnostic->span->startLine . ': ' . $diagnostic->message,
            new Linter([new RecursiveMake()])->lint(MakefileParser::parse($source)),
        );
    }

    #[Test]
    public function acceptsTheMakeVariable(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            all:
            	$(MAKE) -C sub
            	+${MAKE} -f other.mk
            	cd sub && $(MAKE)
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }

    #[Test]
    public function ignoresMakeOutsideCommandPosition(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            all:
            	echo make
            	cmake ..
            	mkdir make
            	echo 'cd sub && make'
            	sh -c "make all"
            	\make
            	"make"
            	echo done # make
            	for name in make gmake; do echo $$name; done
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }

    #[Test]
    public function ignoresMakeOutsideRecipes(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            TOOL = make
            VERSION := $(shell make --version)
            define run
            	make $(1)
            endef
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }

    #[Test]
    public function reportsMakeAfterShellOperators(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            all:
            	cd sub && make
            	true; gmake all
            	false || ./tools/make
            	for dir in a b; do make -C $$dir; done
            	if test -d sub; then make -C sub; fi
            	{ make; }
            	@-cd sub && \
            	  make
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame(
            [
                "2: Use \$(MAKE) instead of 'make' to run make recursively",
                "3: Use \$(MAKE) instead of 'gmake' to run make recursively",
                "4: Use \$(MAKE) instead of './tools/make' to run make recursively",
                "5: Use \$(MAKE) instead of 'make' to run make recursively",
                "6: Use \$(MAKE) instead of 'make' to run make recursively",
                "7: Use \$(MAKE) instead of 'make' to run make recursively",
                "8: Use \$(MAKE) instead of 'make' to run make recursively",
            ],
            $reported,
        );
    }

    #[Test]
    public function reportsMakeAtTheStartOfACommand(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            all: ; make sub
            sub:
            	make -C sub
            	@phmake -f other.mk
            	+make
            	-/usr/bin/make clean
            	MAKEFLAGS= make
            	exec env CC=cc make
            	make "unterminated
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame(
            [
                "1: Use \$(MAKE) instead of 'make' to run make recursively",
                "3: Use \$(MAKE) instead of 'make' to run make recursively",
                "4: Use \$(MAKE) instead of 'phmake' to run make recursively",
                "5: Use \$(MAKE) instead of 'make' to run make recursively",
                "6: Use \$(MAKE) instead of '/usr/bin/make' to run make recursively",
                "7: Use \$(MAKE) instead of 'make' to run make recursively",
                "8: Use \$(MAKE) instead of 'make' to run make recursively",
                "9: Use \$(MAKE) instead of 'make' to run make recursively",
            ],
            $reported,
        );
    }
}
