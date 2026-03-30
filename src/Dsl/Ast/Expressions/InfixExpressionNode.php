<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class InfixExpressionNode implements ExprNode
{
    public function __construct(
        public ExprNode $left,
        public string $operator,
        public ExprNode $right,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'InfixExpression',
            'left' => $this->left->toArray(),
            'operator' => $this->operator,
            'right' => $this->right->toArray(),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $left = $data['left'] ?? [];
        if (!is_array($left)) {
            throw new \RuntimeException('Expected array for left');
        }

        $operator = $data['operator'] ?? '';
        if (!is_string($operator)) {
            throw new \RuntimeException('Expected string for operator');
        }

        $right = $data['right'] ?? [];
        if (!is_array($right)) {
            throw new \RuntimeException('Expected array for right');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            left: ExprNodeFactory::fromArray($left),
            operator: $operator,
            right: ExprNodeFactory::fromArray($right),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
