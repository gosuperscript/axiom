<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * A singleton of a scalar base. Substitutable for its base, never the
 * reverse. Numeric literal identity is loose (5 and 5.0 denote the same
 * Number); boolean and string identity is strict.
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

        if (is_bool($this->value) || is_string($this->value)) {
            return $this->value === $other->value;
        }

        return $this->value == $other->value;
    }
}
