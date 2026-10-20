<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * Numeric values. The integer refinement narrows the domain to PHP ints —
 * a number written as a decimal is never an integer, whatever its value. It
 * is a flag rather than a constructor of its own because Integer is a
 * Number, and every relation over two numbers holds for their refinements
 * except where it asks which values fit.
 */
final class NumberShape extends Shape
{
    public function __construct(
        public readonly bool $integer = false,
    ) {}

    public function equals(Shape $other): bool
    {
        return $other instanceof self && $other->integer === $this->integer;
    }
}
