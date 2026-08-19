<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * A possibly-absent value: denotes None | Some(inner).
 *
 * Option constructors remain nested. The runtime represents an inner option
 * as an Option value inside the compiled node's outer Option channel, so
 * Option<Option<T>> can distinguish None from Some(None).
 */
final class OptionShape extends Shape
{
    public readonly Shape $inner;

    public function __construct(Shape $inner)
    {
        $this->inner = $inner;
    }

    public function equals(Shape $other): bool
    {
        return $other instanceof self && $this->inner->equals($other->inner);
    }
}
