<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class ListLiteralNode implements ExprNode
{
    /**
     * @param list<ExprNode> $elements
     */
    public function __construct(
        public array $elements,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'ListLiteral',
            'elements' => array_map(fn(ExprNode $el) => $el->toArray(), $this->elements),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $elements = $data['elements'] ?? [];
        if (!is_array($elements)) {
            throw new \RuntimeException('Expected array for elements');
        }

        $loc = $data['loc'] ?? null;

        /** @var list<ExprNode> $elementNodes */
        $elementNodes = [];
        foreach ($elements as $el) {
            if (!is_array($el)) {
                throw new \RuntimeException('Expected array for element');
            }
            $elementNodes[] = ExprNodeFactory::fromArray($el);
        }

        return new self(
            elements: $elementNodes,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
