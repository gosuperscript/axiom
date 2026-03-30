<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\PrettyPrinter;

use RuntimeException;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IndexExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ListLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
use Superscript\Axiom\Dsl\Ast\Node;
use Superscript\Axiom\Dsl\Ast\ProgramNode;
use Superscript\Axiom\Dsl\Ast\Statements\NamespaceDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;
use Superscript\Axiom\Dsl\DslLiteralExtension;
use Superscript\Axiom\Dsl\OperatorRegistry;

final class PrettyPrinter
{
    /**
     * @param list<DslLiteralExtension> $literalExtensions
     */
    public function __construct(
        private OperatorRegistry $operatorRegistry,
        private array $literalExtensions = [],
    ) {}

    public function print(ProgramNode $program): string
    {
        $lines = [];

        foreach ($program->body as $node) {
            $lines[] = $this->printNode($node);
        }

        return implode("\n", $lines);
    }

    private function printNode(Node $node, string $indent = ''): string
    {
        if ($node instanceof SymbolDeclarationNode) {
            $type = $this->printTypeAnnotation($node->type);
            $expr = $this->printExpression($node->expression);

            return "{$indent}{$node->name}: {$type} = {$expr}";
        }

        if ($node instanceof NamespaceDeclarationNode) {
            $lines = ["{$indent}namespace {$node->name} {"];

            foreach ($node->body as $child) {
                $lines[] = $this->printNode($child, $indent . '    ');
            }

            $lines[] = "{$indent}}";

            return implode("\n", $lines);
        }

        throw new RuntimeException('Cannot print node of type ' . $node::class);
    }

    public function printExpression(ExprNode $node, int $parentPrecedence = 0): string
    {
        foreach ($this->literalExtensions as $ext) {
            if ($ext->handles($node)) {
                return $ext->prettyPrint($node, $this, $parentPrecedence);
            }
        }

        return match (true) {
            $node instanceof LiteralNode => $this->printLiteral($node),
            $node instanceof IdentifierNode => $node->name,
            $node instanceof MemberExpressionNode => $this->printMemberExpression($node),
            $node instanceof IndexExpressionNode => $this->printIndexExpression($node),
            $node instanceof InfixExpressionNode => $this->printInfixExpression($node, $parentPrecedence),
            $node instanceof UnaryExpressionNode => $this->printUnaryExpression($node, $parentPrecedence),
            $node instanceof CoercionExpressionNode => $this->printCoercionExpression($node),
            $node instanceof ListLiteralNode => $this->printListLiteral($node),
            $node instanceof DictLiteralNode => $this->printDictLiteral($node),
            default => throw new RuntimeException('Cannot print expression of type ' . $node::class),
        };
    }

    private function printLiteral(LiteralNode $node): string
    {
        return $node->raw;
    }

    private function printMemberExpression(MemberExpressionNode $node): string
    {
        $object = $this->printExpression($node->object, PHP_INT_MAX);

        return "{$object}.{$node->property}";
    }

    private function printIndexExpression(IndexExpressionNode $node): string
    {
        $object = $this->printExpression($node->object, PHP_INT_MAX);
        $index = $this->printExpression($node->index);

        return "{$object}[{$index}]";
    }

    private function printInfixExpression(InfixExpressionNode $node, int $parentPrecedence): string
    {
        $op = $this->operatorRegistry->get($node->operator);
        $precedence = $op !== null ? $op->precedence : 0;

        $left = $this->printExpression($node->left, $precedence);
        $right = $this->printExpression($node->right, $precedence + 1);

        $result = "{$left} {$node->operator} {$right}";

        if ($precedence < $parentPrecedence) {
            return "({$result})";
        }

        return $result;
    }

    private function printUnaryExpression(UnaryExpressionNode $node, int $parentPrecedence): string
    {
        if ($node->operator === 'not' && $node->operand instanceof InfixExpressionNode && $node->operand->operator === 'in') {
            $inOp = $this->operatorRegistry->get('in');
            $precedence = $inOp !== null ? $inOp->precedence : 0;

            $left = $this->printExpression($node->operand->left, $precedence);
            $right = $this->printExpression($node->operand->right, $precedence + 1);

            $result = "{$left} not in {$right}";

            if ($precedence < $parentPrecedence) {
                return "({$result})";
            }

            return $result;
        }

        $op = $this->operatorRegistry->get($node->operator);
        $precedence = $op !== null ? $op->precedence : 0;

        $operand = $this->printExpression($node->operand, $precedence);

        $separator = $this->operatorRegistry->isKeywordOperator($node->operator) ? ' ' : '';
        $result = "{$node->operator}{$separator}{$operand}";

        if ($precedence < $parentPrecedence) {
            return "({$result})";
        }

        return $result;
    }

    private function printCoercionExpression(CoercionExpressionNode $node): string
    {
        $expr = $this->printExpression($node->expression, PHP_INT_MAX);
        $type = $this->printTypeAnnotation($node->targetType);

        return "{$expr} as {$type}";
    }

    private function printListLiteral(ListLiteralNode $node): string
    {
        $elements = array_map(
            fn(ExprNode $el) => $this->printExpression($el),
            $node->elements,
        );

        return '[' . implode(', ', $elements) . ']';
    }

    private function printDictLiteral(DictLiteralNode $node): string
    {
        $entries = array_map(
            fn(array $entry) => $this->printExpression($entry['key']) . ': ' . $this->printExpression($entry['value']),
            $node->entries,
        );

        return '{' . implode(', ', $entries) . '}';
    }

    private function printTypeAnnotation(TypeAnnotationNode $node): string
    {
        if ($node->args === []) {
            return $node->keyword;
        }

        $args = array_map(
            fn(TypeAnnotationNode $arg) => $this->printTypeAnnotation($arg),
            $node->args,
        );

        return $node->keyword . '<' . implode(', ', $args) . '>';
    }
}
