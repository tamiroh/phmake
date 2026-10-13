<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Tests\Testing;

use LogicException;
use Tamiroh\Phmake\Parser\Ast;
use Tamiroh\Phmake\Parser\Ast\Node;
use Tamiroh\Phmake\Parser\Ast\RawNode;
use Tamiroh\Phmake\Parser\Ast\RecipeNode;
use Tamiroh\Phmake\Parser\Ast\TriviaNode;

use function json_encode;
use function strrpos;
use function substr;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A structural view for assertions, with source positions tested separately.
 */
final class AstTree
{
    public static function dump(Node $node, string $indent = ''): string
    {
        $separator = strrpos($node::class, '\\');
        $line = $indent . ($separator === false ? $node::class : substr($node::class, $separator + 1));
        foreach (self::fields($node) as $name => $value) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new LogicException('AST tree values must be JSON encodable');
            }
            $line .= ' ' . $name . '=' . $encoded;
        }
        foreach ($node->syntaxChildren() as $child) {
            $line .= "\n" . self::dump($child, $indent . '  ');
        }
        return $line;
    }

    /**
     * @return array<string, string|bool|list<string>>
     */
    private static function fields(Node $node): array
    {
        return match (true) {
            $node instanceof Ast\AssignmentNode => [
                'name' => $node->name,
                'operator' => $node->operator,
                'expression' => $node->expression,
                'modifiers' => $node->modifiers,
                'exportAll' => $node->exportAll,
            ],
            $node instanceof Ast\TargetAssignmentNode => ['targets' => $node->targets, 'exportAll' => $node->exportAll],
            $node instanceof Ast\DefineHeaderNode => ['skipWhenInactive' => $node->skipWhenInactive],
            $node instanceof Ast\DirectiveNode => [
                'directive' => $node->directive,
                'expression' => $node->expression,
                'modifiers' => $node->modifiers,
            ],
            $node instanceof Ast\ConditionalDirectiveNode, $node instanceof Ast\IncludeNode => [
                'directive' => $node->directive,
                'expression' => $node->expression,
            ],
            $node instanceof Ast\RuleNode => ['header' => $node->header, 'exportAll' => $node->exportAll],
            $node instanceof Ast\ExpressionNode => ['header' => $node->header],
            $node instanceof RecipeNode => ['raw' => $node->raw, 'expression' => $node->expression],
            $node instanceof RawNode, $node instanceof TriviaNode => ['raw' => $node->raw],
            default => [],
        };
    }
}
