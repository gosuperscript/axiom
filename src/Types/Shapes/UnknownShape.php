<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * The shape of a value nothing is known about. Under assignability it is
 * accepted only by itself; at operand positions it is refused outright
 * (bridge with Coerce or Ascription); under overlap it matches everything,
 * because an unknown value can never be ruled out. Derived, never
 * authored.
 */
final class UnknownShape extends Shape
{
    public function equals(Shape $other): bool
    {
        return $other instanceof self;
    }
}
