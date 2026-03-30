<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use RuntimeException;

final class ExprNodeFactory
{
    /**
     * @param mixed[] $data
     */
    public static function fromArray(array $data): ExprNode
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new RuntimeException('Expected string for type');
        }

        return match ($type) {
            'Literal' => LiteralNode::fromArray($data),
            'Identifier' => IdentifierNode::fromArray($data),
            'MemberExpression' => MemberExpressionNode::fromArray($data),
            'IndexExpression' => IndexExpressionNode::fromArray($data),
            'InfixExpression' => InfixExpressionNode::fromArray($data),
            'UnaryExpression' => UnaryExpressionNode::fromArray($data),
            'CoercionExpression' => CoercionExpressionNode::fromArray($data),
            'MatchExpression' => MatchExpressionNode::fromArray($data),
            'CallExpression' => CallExpressionNode::fromArray($data),
            'PipeExpression' => PipeExpressionNode::fromArray($data),
            'Lambda' => LambdaNode::fromArray($data),
            'ListLiteral' => ListLiteralNode::fromArray($data),
            'DictLiteral' => DictLiteralNode::fromArray($data),
            default => throw new RuntimeException("Unknown expression node type: {$type}"),
        };
    }
}
