<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class PipeExpressionNode implements ExprNode
{
    public function __construct(
        public ExprNode $left,
        public ExprNode $right,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'PipeExpression',
            'left' => $this->left->toArray(),
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

        $right = $data['right'] ?? [];
        if (!is_array($right)) {
            throw new \RuntimeException('Expected array for right');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            left: ExprNodeFactory::fromArray($left),
            right: ExprNodeFactory::fromArray($right),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
