<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * Bottom: the empty value set. Assignable to everything, inhabited by
 * nothing. The union identity, and the inner shape of the null literal's
 * type (Option<Never> denotes exactly {null}).
 */
final class NeverShape extends Shape
{
    public function equals(Shape $other): bool
    {
        return $other instanceof self;
    }
}
