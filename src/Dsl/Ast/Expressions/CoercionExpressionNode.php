<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;

final readonly class CoercionExpressionNode implements ExprNode
{
    public function __construct(
        public ExprNode $expression,
        public TypeAnnotationNode $targetType,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'CoercionExpression',
            'expression' => $this->expression->toArray(),
            'targetType' => $this->targetType->toArray(),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $expression = $data['expression'] ?? [];
        if (!is_array($expression)) {
            throw new \RuntimeException('Expected array for expression');
        }

        $targetType = $data['targetType'] ?? [];
        if (!is_array($targetType)) {
            throw new \RuntimeException('Expected array for targetType');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            expression: ExprNodeFactory::fromArray($expression),
            targetType: TypeAnnotationNode::fromArray($targetType),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
