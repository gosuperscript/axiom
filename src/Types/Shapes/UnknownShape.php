<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * The statically unnameable. Admissible at every operand position (nothing
 * can be ruled out); certifies nothing under assignability (accepted only
 * by itself). Derived, never authored.
 */
final class UnknownShape extends Shape
{
    public function equals(Shape $other): bool
    {
        return $other instanceof self;
    }
}
