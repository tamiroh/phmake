<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Linter\Rules;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Linter\Diagnostic;
use Tamiroh\Phmake\Linter\Linter;
use Tamiroh\Phmake\Linter\Rules\MissingPhony;
use Tamiroh\Phmake\Parser\MakefileParser;

use function array_map;

final class MissingPhonyTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function lint(string $source): array
    {
        return array_map(
            static fn(Diagnostic $diagnostic): string => $diagnostic->span->startLine . ': ' . $diagnostic->message,
            new Linter([new MissingPhony()])->lint(MakefileParser::parse($source)),
        );
    }

    #[Test]
    public function acceptsDeclarationsInAnyConditionalBranch(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            ifdef CI
            .PHONY: test
            endif
            test:
            	phpunit
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }

    #[Test]
    public function acceptsDeclaredTargets(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            .PHONY: test
            test:
            	phpunit

            check: test
            .PHONY: check lint
            lint:
            	mago lint
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }

    #[Test]
    public function acceptsTargetsThatAreNotPlainNames(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            $(BOX):
            	curl -o box.phar https://example.com
            %.o: %.c
            	cc -c $<
            objects: %.o: %.c
            	cc -c $<
            dist/app.phar:
            	box compile
            .DEFAULT:
            	echo unknown
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }

    #[Test]
    public function acceptsTargetsWhoseRecipeRefersToThem(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            dist:
            	mkdir -p $@
            cache: ; mkdir -p $(@D)/cache
            venv:
            	python -m venv venv
            app: main.o
            	$(CC) -o ${@} main.o
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }

    #[Test]
    public function ignoresRecipeLessTargetsWithoutPhonyPrerequisites(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            FORCE:
            app: main.o util.o
            phar: dist/app.phar
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }

    #[Test]
    public function reportsConventionalNamesEvenWhenTheRecipeRepeatsThem(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            lint:
            	mago lint
            install:
            	npm install
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame(
            [
                "1: Target 'lint' looks phony but is not declared in .PHONY",
                "3: Target 'install' looks phony but is not declared in .PHONY",
            ],
            $reported,
        );
    }

    #[Test]
    public function reportsRecipeLessTargetsThatDependOnPhonyTargets(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            all: check
            check: lint | build
            .PHONY: lint
            lint:
            	mago lint
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame(
            [
                "1: Target 'all' looks phony but is not declared in .PHONY",
                "2: Target 'check' looks phony but is not declared in .PHONY",
            ],
            $reported,
        );
    }

    #[Test]
    public function reportsTargetsWhoseRecipeNeverRefersToThem(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            .PHONY: lint
            lint:
            	mago lint

            deploy: dist/app.phar
            	./scripts/deploy.sh $<
            shell-args: ; echo "$$@"
            clean docs::
            	rm -rf build
            42:
            	echo answer
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame(
            [
                "5: Target 'deploy' looks phony but is not declared in .PHONY",
                "7: Target 'shell-args' looks phony but is not declared in .PHONY",
                "8: Target 'clean' looks phony but is not declared in .PHONY",
                "8: Target 'docs' looks phony but is not declared in .PHONY",
                "10: Target '42' looks phony but is not declared in .PHONY",
            ],
            $reported,
        );
    }

    #[Test]
    public function staysSilentWhenPhonyTargetsAreComputed(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            PHONY += test
            test:
            	phpunit
            .PHONY: $(PHONY)
            MAKEFILE;

        // Act
        $reported = self::lint($source);

        // Assert
        self::assertSame([], $reported);
    }
}
