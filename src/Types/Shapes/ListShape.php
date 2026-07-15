<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * A length-bounded list; a plain list is bounds [0, ∞). Bounds participate
 * in subtyping and overlap. max of null means unbounded.
 */
final class ListShape extends Shape
{
    public function __construct(
        public readonly Shape $element,
        public readonly int $min = 0,
        public readonly ?int $max = null,
    ) {
        // Relations trust the bounds (overlap tests min === 0 for the
        // shared empty member), so an impossible claim must not construct.
        if ($this->min < 0) {
            throw new \InvalidArgumentException(sprintf('List bounds must be sensible: min %d is negative.', $this->min));
        }

        if ($this->max !== null && $this->max < $this->min) {
            throw new \InvalidArgumentException(sprintf('List bounds must be sensible: max %d is below min %d.', $this->max, $this->min));
        }
    }

    public function equals(Shape $other): bool
    {
        return $other instanceof self
            && $this->min === $other->min
            && $this->max === $other->max
            && $this->element->equals($other->element);
    }
}
