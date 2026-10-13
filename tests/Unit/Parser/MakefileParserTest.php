<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Unit\Parser;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tamiroh\Phmake\Formatter\Formatter;
use Tamiroh\Phmake\Linter\Diagnostic;
use Tamiroh\Phmake\Linter\Linter;
use Tamiroh\Phmake\Linter\Rules\Rule;
use Tamiroh\Phmake\Parser\Ast\AssignmentNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\RuleNode;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Tests\Testing\AstTree;

use function array_map;
use function strlen;

final class MakefileParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        yield 'empty' => [''];
        yield 'line endings and BOM' => ["\xEF\xBB\xBFA = a  \r\n# comment\r\n\r\nlast:"];
        yield 'continuations' => ["A = a \\\n  b  # comment\nall:; echo hi \\\n there\n\techo a \\\n\t b\n"];
        yield 'nested blocks' => ["ifdef A\nifdef B\nX = 1\nelse\nX = 2\nendif\nelse ifeq (a,a)\nX = 3\nendif\n"];
        yield 'define body' => ["define A\ndefine B\nifdef X\nendef\nendef\n"];
        yield 'incomplete blocks' => ["ifdef A\nifdef B\nX = 1\n"];
        yield 'dynamic syntax' => [".RECIPEPREFIX := \$(P)\nall:\n>echo hi\n\$(eval A = x)\n\$(RULE)\n"];
        yield 'significant spaces' => ["A = value  # comment\nall: private B += value  \n\t echo '# literal'  \n"];
    }

    #[Test]
    public function acceptsSideEffectingExpressionsWithoutEvaluatingThem(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            A != exit 97
            B := $(shell exit 98)
            include /nonexistent/phmake-ast-test.mk
            $(eval C := $(error evaluated))
            MAKEFILE;

        // Act
        $ast = MakefileParser::parse($source);

        // Assert
        self::assertSame($source, $ast->source());
        self::assertSame(<<<'TREE'
            MakefileNode
              AssignmentNode name="A" operator="!=" expression="exit 97" modifiers=[] exportAll=false
              AssignmentNode name="B" operator=":=" expression="$(shell exit 98)" modifiers=[] exportAll=false
              IncludeNode directive="include" expression="/nonexistent/phmake-ast-test.mk"
              ExpressionNode header="$(eval C := $(error evaluated))"
            TREE, AstTree::dump($ast));
    }

    #[Test]
    #[DataProvider('sources')]
    public function formatterPreservesBytesAndIsIdempotent(string $source): void
    {
        $formatter = new Formatter();
        $formatted = $formatter->format(MakefileParser::parse($source));
        self::assertSame($source, $formatted);
        self::assertSame($formatted, $formatter->format(MakefileParser::parse($formatted)));
    }

    #[Test]
    public function preservesScopedAssignmentsAndDefineBodies(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            %.o: private CFLAGS += $(FLAGS)
            define BODY :=
            # literal
            A = body
            endef

            MAKEFILE;

        // Act
        $ast = MakefileParser::parse($source);

        // Assert
        self::assertSame(<<<'TREE'
            MakefileNode
              TargetAssignmentNode targets="%.o" exportAll=false
                AssignmentNode name="CFLAGS" operator="+=" expression="$(FLAGS)" modifiers=["private"] exportAll=false
              DefineNode
                DefineHeaderNode skipWhenInactive=true
                  AssignmentNode name="BODY" operator=":=" expression="" modifiers=[] exportAll=false
                RawNode raw="# literal\nA = body\n"
                DirectiveNode directive="endef" expression="" modifiers=[]
            TREE, AstTree::dump($ast));
        self::assertSame($source, $ast->source());
    }

    #[Test]
    public function retainsAssignmentContinuationWithoutFolding(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            A += first \
              second

            MAKEFILE;

        // Act
        $ast = MakefileParser::parse($source);

        // Assert
        self::assertSame(<<<'TREE'
            MakefileNode
              AssignmentNode name="A" operator="+=" expression="first \\\n  second" modifiers=[] exportAll=false
            TREE, AstTree::dump($ast));
        self::assertCount(1, $ast->children);
        self::assertSame(3, $ast->children[0]->span->endLine);
    }

    #[Test]
    public function retainsAssignmentsRulesAndAllBranches(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            A = $(B)
            A := later
            ifdef A
            app: main.o
            else
            app: util.o
            endif

            MAKEFILE;

        // Act
        $ast = MakefileParser::parse($source, 'sample.mk');

        // Assert
        self::assertSame(<<<'TREE'
            MakefileNode
              AssignmentNode name="A" operator="=" expression="$(B)" modifiers=[] exportAll=false
              AssignmentNode name="A" operator=":=" expression="later" modifiers=[] exportAll=false
              ConditionalNode
                ConditionalBranchNode
                  ConditionalDirectiveNode directive="ifdef" expression="A"
                  RuleNode header="app: main.o" exportAll=false
                ConditionalBranchNode
                  ConditionalDirectiveNode directive="else" expression=""
                  RuleNode header="app: util.o" exportAll=false
                ConditionalDirectiveNode directive="endif" expression=""
            TREE, AstTree::dump($ast));
        self::assertCount(3, $ast->children);
        $first = $ast->children[0];
        self::assertSame('sample.mk', $first->span->file);
        self::assertSame(1, $first->span->startLine);
        self::assertSame(2, $first->span->endLine);
        self::assertSame(strlen("A = \$(B)\n"), $first->span->endOffset);
    }

    #[Test]
    public function retainsSeparateRulesAndInlineRecipeSource(): void
    {
        // Arrange
        $source = <<<'MAKEFILE'
            app: $(OBJECTS)
            app: util.o; echo '# literal'

            MAKEFILE;

        // Act
        $ast = MakefileParser::parse($source);

        // Assert
        self::assertSame(<<<'TREE'
            MakefileNode
              RuleNode header="app: $(OBJECTS)" exportAll=false
              RuleNode header="app: util.o" exportAll=false
                RecipeNode raw=" echo '# literal'\n" expression=" echo '# literal'"
            TREE, AstTree::dump($ast));
        self::assertCount(2, $ast->children);
        $second = $ast->children[1];
        self::assertInstanceOf(RuleNode::class, $second);
        self::assertNotNull($second->inlineRecipe);
        self::assertSame(2, $second->inlineRecipe->span->startLine);
        self::assertSame(13, $second->inlineRecipe->span->startColumn);
    }

    #[Test]
    public function visitsAssignmentsInInactiveBranches(): void
    {
        $rule = new class() implements Rule {
            #[Override]
            public function inspect(Node $node): array
            {
                return $node instanceof AssignmentNode ? [new Diagnostic('assignment', $node->name, $node->span)] : [];
            }
        };
        $ast = MakefileParser::parse("ifeq (a,b)\nA = one\nelse\nB = two\nendif\n");
        $diagnostics = new Linter([$rule])->lint($ast);
        self::assertSame(
            ['A', 'B'],
            array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->message, $diagnostics),
        );
        self::assertSame(
            [2, 4],
            array_map(static fn(Diagnostic $diagnostic): int => $diagnostic->span->startLine, $diagnostics),
        );
    }
}
