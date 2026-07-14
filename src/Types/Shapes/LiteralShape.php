<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

use Superscript\Axiom\Operators\ValueEquality;

/**
 * A singleton of a scalar base. Substitutable for its base, never the
 * reverse. Literal identity is value equality — the same definition the
 * runtime matcher and the comparison operators consume (5 and 5.0 denote
 * the same Number; boolean and string identity is strict).
 */
final class LiteralShape extends Shape
{
    public readonly Shape $base;

    public function __construct(
        public readonly bool|int|float|string $value,
    ) {
        $this->base = match (true) {
            is_bool($value) => new BooleanShape(),
            is_string($value) => new StringShape(),
            default => new NumberShape(),
        };
    }

    public function equals(Shape $other): bool
    {
        if (!$other instanceof self || !$this->base->equals($other->base)) {
            return false;
        }

        return ValueEquality::equals($this->value, $other->value);
    }
}
