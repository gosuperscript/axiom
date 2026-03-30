<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Patterns;

use RuntimeException;

final class PatternNodeFactory
{
    /**
     * @param mixed[] $data
     */
    public static function fromArray(array $data): PatternNode
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new RuntimeException('Expected string for type');
        }

        return match ($type) {
            'WildcardPattern' => WildcardPatternNode::fromArray($data),
            'LiteralPattern' => LiteralPatternNode::fromArray($data),
            'ExpressionPattern' => ExpressionPatternNode::fromArray($data),
            default => throw new RuntimeException("Unknown pattern node type: {$type}"),
        };
    }
}
