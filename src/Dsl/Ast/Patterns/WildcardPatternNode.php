<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Patterns;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class WildcardPatternNode implements PatternNode
{
    public function __construct(
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'WildcardPattern',
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $loc = $data['loc'] ?? null;

        return new self(
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
