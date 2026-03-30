<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Statements;

use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;

final readonly class SchemaDeclarationNode implements StatementNode
{
    /**
     * @param array<string, TypeAnnotationNode> $fields
     */
    public function __construct(
        public string $name,
        public array $fields,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'SchemaDeclaration',
            'name' => $this->name,
            'fields' => array_map(fn(TypeAnnotationNode $field) => $field->toArray(), $this->fields),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? '';
        if (!is_string($name)) {
            throw new \RuntimeException('Expected string for name');
        }

        $rawFields = $data['fields'] ?? [];
        if (!is_array($rawFields)) {
            throw new \RuntimeException('Expected array for fields');
        }

        $loc = $data['loc'] ?? null;

        /** @var array<string, TypeAnnotationNode> $fieldNodes */
        $fieldNodes = [];
        foreach ($rawFields as $key => $field) {
            if (!is_string($key) || !is_array($field)) {
                throw new \RuntimeException('Expected string key and array value for field');
            }
            $fieldNodes[$key] = TypeAnnotationNode::fromArray($field);
        }

        return new self(
            name: $name,
            fields: $fieldNodes,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
