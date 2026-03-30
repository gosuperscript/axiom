<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\Node;
use Superscript\Axiom\Dsl\Ast\Patterns\PatternNode;
use Superscript\Axiom\Dsl\Ast\Patterns\PatternNodeFactory;

final readonly class MatchArmNode implements Node
{
    public function __construct(
        public PatternNode $pattern,
        public ExprNode $expression,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'MatchArm',
            'pattern' => $this->pattern->toArray(),
            'expression' => $this->expression->toArray(),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $pattern = $data['pattern'] ?? [];
        if (!is_array($pattern)) {
            throw new \RuntimeException('Expected array for pattern');
        }

        $expression = $data['expression'] ?? [];
        if (!is_array($expression)) {
            throw new \RuntimeException('Expected array for expression');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            pattern: PatternNodeFactory::fromArray($pattern),
            expression: ExprNodeFactory::fromArray($expression),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
