<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class UnaryExpressionNode implements ExprNode
{
    public function __construct(
        public string $operator,
        public ExprNode $operand,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'UnaryExpression',
            'operator' => $this->operator,
            'operand' => $this->operand->toArray(),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $operator = $data['operator'] ?? '';
        if (!is_string($operator)) {
            throw new \RuntimeException('Expected string for operator');
        }

        $operand = $data['operand'] ?? [];
        if (!is_array($operand)) {
            throw new \RuntimeException('Expected array for operand');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            operator: $operator,
            operand: ExprNodeFactory::fromArray($operand),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
