<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Statements;

use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;

final readonly class InputDeclarationNode implements StatementNode
{
    public function __construct(
        public string $name,
        public TypeAnnotationNode $type,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'InputDeclaration',
            'name' => $this->name,
            'typeAnnotation' => $this->type->toArray(),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? '';
        if (!is_string($name)) {
            throw new \RuntimeException('Expected string for name');
        }

        $typeAnnotation = $data['typeAnnotation'] ?? [];
        if (!is_array($typeAnnotation)) {
            throw new \RuntimeException('Expected array for typeAnnotation');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            name: $name,
            type: TypeAnnotationNode::fromArray($typeAnnotation),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
