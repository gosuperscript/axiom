<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class MemberExpressionNode implements ExprNode
{
    public function __construct(
        public ExprNode $object,
        public string $property,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'MemberExpression',
            'object' => $this->object->toArray(),
            'property' => $this->property,
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $object = $data['object'] ?? [];
        if (!is_array($object)) {
            throw new \RuntimeException('Expected array for object');
        }

        $property = $data['property'] ?? '';
        if (!is_string($property)) {
            throw new \RuntimeException('Expected string for property');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            object: ExprNodeFactory::fromArray($object),
            property: $property,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
