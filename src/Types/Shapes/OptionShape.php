<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * A possibly-absent value: denotes exactly {null} ∪ values(inner).
 *
 * Nesting collapses on construction — the runtime value domain threads a
 * single null and cannot represent Some(None), so Option<Option<T>> and
 * Option<T> denote the same set.
 */
final class OptionShape extends Shape
{
    public readonly Shape $inner;

    public function __construct(Shape $inner)
    {
        $this->inner = $inner instanceof self ? $inner->inner : $inner;
    }

    public function equals(Shape $other): bool
    {
        return $other instanceof self && $this->inner->equals($other->inner);
    }
}
