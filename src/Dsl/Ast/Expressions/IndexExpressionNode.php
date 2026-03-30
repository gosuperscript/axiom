<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class IndexExpressionNode implements ExprNode
{
    public function __construct(
        public ExprNode $object,
        public ExprNode $index,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'IndexExpression',
            'object' => $this->object->toArray(),
            'index' => $this->index->toArray(),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $object = $data['object'] ?? [];
        if (!is_array($object)) {
            throw new \RuntimeException('Expected array for object');
        }

        $index = $data['index'] ?? [];
        if (!is_array($index)) {
            throw new \RuntimeException('Expected array for index');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            object: ExprNodeFactory::fromArray($object),
            index: ExprNodeFactory::fromArray($index),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
