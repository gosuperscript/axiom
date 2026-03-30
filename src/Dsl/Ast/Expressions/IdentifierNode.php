<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class IdentifierNode implements ExprNode
{
    public function __construct(
        public string $name,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'Identifier',
            'name' => $this->name,
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? '';
        if (!is_string($name)) {
            throw new \RuntimeException('Expected string for name');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            name: $name,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
