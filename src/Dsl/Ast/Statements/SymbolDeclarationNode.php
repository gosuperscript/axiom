<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Statements;

use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNodeFactory;
use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;

final readonly class SymbolDeclarationNode implements StatementNode
{
    public function __construct(
        public string $name,
        public TypeAnnotationNode $type,
        public ExprNode $expression,
        public string $visibility = 'public',
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'SymbolDeclaration',
            'name' => $this->name,
            'typeAnnotation' => $this->type->toArray(),
            'expression' => $this->expression->toArray(),
            'visibility' => $this->visibility,
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

        $expression = $data['expression'] ?? [];
        if (!is_array($expression)) {
            throw new \RuntimeException('Expected array for expression');
        }

        $visibility = $data['visibility'] ?? 'public';
        if (!is_string($visibility)) {
            $visibility = 'public';
        }

        $loc = $data['loc'] ?? null;

        return new self(
            name: $name,
            type: TypeAnnotationNode::fromArray($typeAnnotation),
            expression: ExprNodeFactory::fromArray($expression),
            visibility: $visibility,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
