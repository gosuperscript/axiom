<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Patterns;

use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNodeFactory;
use Superscript\Axiom\Dsl\Ast\Location;

final readonly class ExpressionPatternNode implements PatternNode
{
    public function __construct(
        public ExprNode $expression,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'ExpressionPattern',
            'expression' => $this->expression->toArray(),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $expression = $data['expression'] ?? [];
        if (!is_array($expression)) {
            throw new \RuntimeException('Expected array for expression');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            expression: ExprNodeFactory::fromArray($expression),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
