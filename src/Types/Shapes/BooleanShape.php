<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

final class BooleanShape extends Shape
{
    public function equals(Shape $other): bool
    {
        return $other instanceof self;
    }
}
