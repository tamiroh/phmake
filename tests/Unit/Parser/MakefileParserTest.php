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
use Tamiroh\Phmake\Linter\Rule;
use Tamiroh\Phmake\Parser\Ast\AssignmentNode;
use Tamiroh\Phmake\Parser\Ast\ConditionalNode;
use Tamiroh\Phmake\Parser\Ast\DefineNode;
use Tamiroh\Phmake\Parser\Ast\IncludeNode;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\RuleNode;
use Tamiroh\Phmake\Parser\Ast\TargetAssignmentNode;
use Tamiroh\Phmake\Parser\MakefileParser;

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
        // The parser accepts only text: neither an IO service nor a mutable context exists.
        $source = "A != exit 97\nB := \$(shell exit 98)\ninclude /nonexistent/phmake-ast-test.mk\n\$(eval C := \$(error evaluated))\n";
        $ast = new MakefileParser()->parse($source);
        self::assertSame($source, $ast->source());
        self::assertCount(4, $ast->children);
        self::assertInstanceOf(IncludeNode::class, $ast->children[2]);
        self::assertSame('/nonexistent/phmake-ast-test.mk', $ast->children[2]->expression);
    }

    #[Test]
    #[DataProvider('sources')]
    public function formatterPreservesBytesAndIsIdempotent(string $source): void
    {
        $parser = new MakefileParser();
        $formatter = new Formatter();
        $formatted = $formatter->format($parser->parse($source));
        self::assertSame($source, $formatted);
        self::assertSame($formatted, $formatter->format($parser->parse($formatted)));
    }

    #[Test]
    public function preservesScopedAssignmentsAndDefineBodies(): void
    {
        $ast = new MakefileParser()->parse(
            "%.o: private CFLAGS += \$(FLAGS)\ndefine BODY :=\n# literal\nA = body\nendef\n",
        );
        self::assertCount(2, $ast->children);
        $scoped = $ast->children[0];
        self::assertInstanceOf(TargetAssignmentNode::class, $scoped);
        self::assertSame('%.o', $scoped->targets);
        self::assertSame(['private'], $scoped->assignment->modifiers);
        self::assertSame('$(FLAGS)', $scoped->assignment->expression);
        self::assertInstanceOf(DefineNode::class, $ast->children[1]);
        self::assertSame("define BODY :=\n# literal\nA = body\nendef\n", $ast->children[1]->source());
    }

    #[Test]
    public function retainsAssignmentContinuationWithoutFolding(): void
    {
        $ast = new MakefileParser()->parse("A += first \\\n  second\n");
        self::assertCount(1, $ast->children);
        $assignment = $ast->children[0];
        self::assertInstanceOf(AssignmentNode::class, $assignment);
        self::assertSame("first \\\n  second", $assignment->expression);
        self::assertSame(3, $assignment->span->endLine);
    }

    #[Test]
    public function retainsAssignmentsRulesAndAllBranches(): void
    {
        $ast = new MakefileParser()->parse(
            "A = \$(B)\nA := later\nifdef A\napp: main.o\nelse\napp: util.o\nendif\n",
            'sample.mk',
        );
        self::assertCount(3, $ast->children);
        $first = $ast->children[0];
        self::assertInstanceOf(AssignmentNode::class, $first);
        self::assertSame('$(B)', $first->expression);
        self::assertSame('=', $first->operator);
        $second = $ast->children[1];
        self::assertInstanceOf(AssignmentNode::class, $second);
        self::assertSame(':=', $second->operator);
        $conditional = $ast->children[2];
        self::assertInstanceOf(ConditionalNode::class, $conditional);
        self::assertCount(5, $conditional->children);
        self::assertInstanceOf(RuleNode::class, $conditional->children[1]);
        self::assertInstanceOf(RuleNode::class, $conditional->children[3]);
        self::assertSame('sample.mk', $first->span->file);
        self::assertSame(1, $first->span->startLine);
        self::assertSame(2, $first->span->endLine);
        self::assertSame(strlen("A = \$(B)\n"), $first->span->endOffset);
    }

    #[Test]
    public function retainsSeparateRulesAndInlineRecipeSource(): void
    {
        $ast = new MakefileParser()->parse("app: $(OBJECTS)\napp: util.o; echo '# literal'\n");
        self::assertCount(2, $ast->children);
        $first = $ast->children[0];
        $second = $ast->children[1];
        self::assertInstanceOf(RuleNode::class, $first);
        self::assertInstanceOf(RuleNode::class, $second);
        self::assertSame('app: $(OBJECTS)', $first->header);
        self::assertSame('app: util.o', $second->header);
        self::assertNotNull($second->inlineRecipe);
        self::assertSame(" echo '# literal'\n", $second->inlineRecipe->raw);
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
        $ast = new MakefileParser()->parse("ifeq (a,b)\nA = one\nelse\nB = two\nendif\n");
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
