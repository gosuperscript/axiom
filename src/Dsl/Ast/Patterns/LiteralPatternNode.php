<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Patterns;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class LiteralPatternNode implements PatternNode
{
    public function __construct(
        public mixed $value,
        public string $raw,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'LiteralPattern',
            'value' => $this->value,
            'raw' => $this->raw,
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $raw = $data['raw'] ?? '';
        if (!is_string($raw)) {
            throw new \RuntimeException('Expected string for raw');
        }

        $loc = $data['loc'] ?? null;

        return new self(
            value: $data['value'],
            raw: $raw,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
