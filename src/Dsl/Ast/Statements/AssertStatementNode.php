<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Statements;

use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNodeFactory;
use Superscript\Axiom\Dsl\Ast\Location;

final readonly class AssertStatementNode implements StatementNode
{
    public function __construct(
        public ExprNode $expression,
        public ?string $message = null,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'AssertStatement',
            'expression' => $this->expression->toArray(),
            ...($this->message !== null ? ['message' => $this->message] : []),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $expression = $data['expression'] ?? [];
        if (!is_array($expression)) {
            throw new \RuntimeException('Expected array for expression');
        }

        $message = $data['message'] ?? null;
        $loc = $data['loc'] ?? null;

        return new self(
            expression: ExprNodeFactory::fromArray($expression),
            message: is_string($message) ? $message : null,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
